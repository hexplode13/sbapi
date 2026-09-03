import httpx
import asyncio
import json
import os
from contextlib import asynccontextmanager
from datetime import datetime
from typing import Dict, Optional
from fastapi import FastAPI, WebSocket, HTTPException, Query
from fastapi.middleware.cors import CORSMiddleware  #
from fastapi.responses import HTMLResponse
from pydantic import BaseModel
from sqlalchemy import select, func, update
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from models import Panel, Order, OrderStatus
from database import AsyncSessionLocal, init_db

active_connections: Dict[int, WebSocket] = {}
order_queue: asyncio.Queue = asyncio.Queue()

@asynccontextmanager
async def lifespan(app: FastAPI):
    await init_db()
    task = asyncio.create_task(assign_orders_loop())
    yield
    task.cancel()

app = FastAPI(title="AutoPanel Server", lifespan=lifespan)

# =========================
# НАСТРОЙКА CORS
# =========================
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # Разрешаем запросы с любых адресов (идеально для локальной сети пилотника)
    allow_credentials=True,
    allow_methods=["*"],  # Разрешаем все методы: GET, POST, PUT, DELETE, OPTIONS
    allow_headers=["*"],  # Разрешаем все заголовки
)

class OrderCreate(BaseModel):
    order_number: str
    customer_name: str
    panel_number: Optional[int] = None

async def send_order_to_panel(order: Order):
    if not order.panel:
        return
    panel_number = order.panel.panel_number
    ws = active_connections.get(panel_number)
    if not ws:
        print(f"⚠️ Panel #{panel_number} не онлайн")
        return
    try:
        await ws.send_text(json.dumps({
            "id": order.id,
            "order_number": order.order_number,
            "name": order.customer_name,
            "status": "wait"
        }))
        print(f"📤 Заказ #{order.id} → Panel #{panel_number}")
    except Exception as e:
        print(f"❌ Ошибка отправки: {e}")

# =========================
# УВЕДОМЛЕНИЕ PHP API
# =========================
PHP_API_BASE_URL = "http://192.168.0.213/newapi"

async def notify_php_api_order_closed(order_id: int):
    """Отправляет PATCH запрос в основной API о закрытии заказа"""
    try:
        async with httpx.AsyncClient() as client:
            response = await client.patch(
                f"{PHP_API_BASE_URL}/orders/{order_id}/close-on-table",
                timeout=5.0  # Таймаут 5 секунд, чтобы не вешать сервер, если PHP API недоступен
            )
            if response.status_code in [200, 204]:
                print(f"✅ Заказ #{order_id} успешно закрыт в PHP API | {response.text}")
            else:
                print(f"⚠️ PHP API вернул ошибку для заказа #{order_id}: HTTP {response.status_code} | {response.text}")
    except httpx.RequestError as e:
        print(f"❌ Не удалось соединиться с PHP API для заказа #{order_id}: {e}")
    except Exception as e:
        print(f"❌ Неожиданная ошибка при уведомлении PHP API (заказ #{order_id}): {e}")

@app.websocket("/ws/panel/{panel_number}")
async def panel_websocket(websocket: WebSocket, panel_number: int):
    await websocket.accept()
    async with AsyncSessionLocal() as db:
        result = await db.execute(select(Panel).where(Panel.panel_number == panel_number))
        panel = result.scalar_one_or_none()
        if not panel:
            panel = Panel(panel_number=panel_number, is_online=True, last_seen=datetime.utcnow())
            db.add(panel)
        else:
            panel.is_online = True
            panel.last_seen = datetime.utcnow()
        await db.commit()

    active_connections[panel_number] = websocket
    print(f"✓ Panel #{panel_number} подключена. Онлайн: {len(active_connections)}")

    try:
        while True:
            data = await websocket.receive_text()
            try:
                message = json.loads(data)
                await handle_panel_message(panel_number, message)
            except json.JSONDecodeError:
                print(f"Invalid JSON: {data}")
    except WebSocketDisconnect:
        active_connections.pop(panel_number, None)
        async with AsyncSessionLocal() as db:
            await db.execute(
                update(Panel).where(Panel.panel_number == panel_number)
                .values(is_online=False, last_seen=datetime.utcnow())
            )
            await db.commit()
        print(f"✗ Panel #{panel_number} отключена")

async def handle_panel_message(panel_number: int, message: dict):
    status = message.get("status")
    order_id = message.get("order_id")
    if not order_id or not status:
        return

    status_map = {
        "waiting_for_weight": OrderStatus.WAITING_WEIGHT,
        "weight_detected": OrderStatus.WEIGHT_DETECTED,
        "completed": OrderStatus.COMPLETED,
        "done": OrderStatus.COMPLETED,
        "order_taken": OrderStatus.WEIGHT_DETECTED,
    }
    new_status = status_map.get(status)
    if not new_status:
        return

    async with AsyncSessionLocal() as db:
        result = await db.execute(select(Order).where(Order.id == order_id))
        order = result.scalar_one_or_none()
        if order:
            order.status = new_status
            if new_status == OrderStatus.COMPLETED:
                order.completed_at = datetime.utcnow()
            await db.commit()
            print(f"Panel #{panel_number}: Заказ #{order_id} → {status}")

        # 👇 ДОБАВИТЬ ЭТУ СТРОКУ 👇
        if new_status == OrderStatus.COMPLETED:
            await notify_php_api_order_closed(int(order.order_number))    

@app.post("/api/orders")
async def create_order(order: OrderCreate):
    try:
        async with AsyncSessionLocal() as db:
            new_order = Order(
                order_number=order.order_number,
                customer_name=order.customer_name,
                status=OrderStatus.QUEUED
            )
            if order.panel_number:
                result = await db.execute(select(Panel).where(Panel.panel_number == order.panel_number))
                panel = result.scalar_one_or_none()
                if panel and panel.is_online:
                    new_order.panel_id = panel.id
                    new_order.status = OrderStatus.ASSIGNED
                    new_order.assigned_at = datetime.utcnow()
            
            db.add(new_order)
            await db.commit()
            await db.refresh(new_order)

            if new_order.panel_id:
                result = await db.execute(select(Panel).where(Panel.id == new_order.panel_id))
                new_order.panel = result.scalar_one_or_none()

            order_id = new_order.id
            assigned_panel = new_order.panel.panel_number if new_order.panel else None

        if assigned_panel:
            await send_order_to_panel(new_order)
        else:
            await order_queue.put(order_id)

        return {
            "status": "ok",
            "order_id": order_id,
            "panel_number": assigned_panel,
            "message": "Заказ создан"
        }
    except Exception as e:
        import traceback
        traceback.print_exc()
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/api/orders")
async def list_orders(status: Optional[str] = None, limit: int = Query(default=50, le=100)):
    async with AsyncSessionLocal() as db:
        query = (
            select(Order)
            .options(selectinload(Order.panel))
            .order_by(Order.created_at.desc())
            .limit(limit)
        )
        if status:
            query = query.where(Order.status == status)
        
        result = await db.execute(query)
        orders = result.scalars().all()
        return [{
            "id": o.id,
            "order_number": o.order_number,
            "customer_name": o.customer_name,
            "status": o.status,
            "panel_number": o.panel.panel_number if o.panel else None,
            "created_at": o.created_at.isoformat(),
        } for o in orders]

@app.get("/api/panels")
async def list_panels():
    async with AsyncSessionLocal() as db:
        result = await db.execute(
            select(Panel)
            .options(selectinload(Panel.orders))
            .order_by(Panel.panel_number)
        )
        panels = result.scalars().all()
        data = []
        for p in panels:
            active = len([
                o for o in p.orders 
                if o.status in [OrderStatus.ASSIGNED, OrderStatus.WAITING_WEIGHT, OrderStatus.WEIGHT_DETECTED]
            ])
            data.append({
                "panel_number": p.panel_number,
                "name": p.name,
                "is_online": p.is_online,
                "last_seen": p.last_seen.isoformat() if p.last_seen else None,
                "active_orders": active
            })
        return data

# === СПИСОК СВОБОДНЫХ ПАНЕЛЕЙ ===
@app.get("/api/panels/free")
async def get_free_panels():
    async with AsyncSessionLocal() as db:
        # Загружаем все панели вместе с их заказами
        result = await db.execute(
            select(Panel)
            .options(selectinload(Panel.orders))
            .order_by(Panel.panel_number)
        )
        panels = result.scalars().all()
        
        free_panels = []
        for p in panels:
            # Считаем активные заказы (те, что еще не завершены)
            active_orders = [
                o for o in p.orders 
                if o.status in [
                    OrderStatus.ASSIGNED, 
                    OrderStatus.WAITING_WEIGHT, 
                    OrderStatus.WEIGHT_DETECTED,
                    OrderStatus.QUEUED
                ]
            ]
            
            # Если активных заказов нет, панель свободна
            if len(active_orders) == 0:
                free_panels.append({
                    "panel_number": p.panel_number,
                    "name": p.name,
                    "is_online": p.is_online,
                    "last_seen": p.last_seen.isoformat() if p.last_seen else None,
                    "status": "free"
                })
                
        return {
            "count": len(free_panels),
            "free_panels": free_panels
        }

# === ПРИНУДИТЕЛЬНЫЙ СБРОС ПАНЕЛИ ===
@app.post("/api/panels/{panel_number}/reset")
async def reset_panel(panel_number: int):
    """Отправка команды сброса на конкретную панель"""
    ws = active_connections.get(panel_number)
    
    if not ws:
        raise HTTPException(status_code=404, detail=f"Panel #{panel_number} не онлайн")
    
    try:
        await ws.send_text(json.dumps({
            "command": "reset",
            "order_id": None
        }))
        
        # Также обновляем заказ в БД если он есть
        async with AsyncSessionLocal() as db:
            # 1. Сначала находим саму панель, чтобы получить её panel_id
            panel_result = await db.execute(select(Panel).where(Panel.panel_number == panel_number))
            panel = panel_result.scalar_one_or_none()
            
            if not panel:
                raise HTTPException(status_code=404, detail="Панель не найдена")

            # 2. Ищем активные заказы на этой панели
            active_statuses = [OrderStatus.ASSIGNED, OrderStatus.WAITING_WEIGHT, OrderStatus.WEIGHT_DETECTED]
            order_result = await db.execute(
                select(Order)
                .where(Order.panel_id == panel.id)
                .where(Order.status.in_(active_statuses))
                .order_by(Order.created_at.desc()) # На случай если их несколько, берем самый свежий
                .limit(1)
            )
            active_order = order_result.scalar_one_or_none()

            # 3. Если активных заказов нет, просто возвращаем успех, не дергая PHP API
            if not active_order:
                return {"status": "ok", "message": f"Панель #{panel_number} уже свободна"}

            # 4. Закрываем найденный заказ
            active_order.status = OrderStatus.COMPLETED
            active_order.completed_at = datetime.utcnow()
            await db.commit()

            # 5. Уведомляем PHP API только о реальном ID заказа
            await notify_php_api_order_closed(int(active_order.order_number))
            
        return {
            "status": "ok",
            "message": f"Команда сброса отправлена на Panel #{panel_number}",
            "orders_completed": len(active_orders)
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/api/panels/{panel_number}/factory-reset")
async def factory_reset_panel(panel_number: int):
    """Полный сброс панели: стирание настроек и переход в портал WiFi"""
    ws = active_connections.get(panel_number)
    
    if not ws:
        raise HTTPException(status_code=404, detail=f"Panel #{panel_number} не онлайн")
    
    try:
        await ws.send_text(json.dumps({
            "command": "factory_reset"
        }))
        return {
            "status": "ok",
            "message": f"Команда полного сброса отправлена на Panel #{panel_number}. Устройство перезагрузится в режим настройки WiFi."
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
    

@app.get("/api/status")
async def server_status():
    return {
        "mode": "sqlite",
        "database": "SQLite (Docker Volume)",
        "online_panels": list(active_connections.keys()),
        "server_time": datetime.utcnow().isoformat()
    }

async def assign_orders_loop():
    while True:
        try:
            order_id = await order_queue.get()
            async with AsyncSessionLocal() as db:
                result = await db.execute(select(Order).where(Order.id == order_id))
                order = result.scalar_one_or_none()
                if not order or order.status != OrderStatus.QUEUED:
                    continue
                
                result = await db.execute(
                    select(Panel).where(Panel.is_online == True).order_by(Panel.last_seen.desc())
                )
                panels = result.scalars().all()
                
                for panel in panels:
                    res_count = await db.execute(
                        select(func.count()).select_from(Order)
                        .where(Order.panel_id == panel.id)
                        .where(Order.status.in_([
                            OrderStatus.ASSIGNED, OrderStatus.WAITING_WEIGHT, OrderStatus.WEIGHT_DETECTED
                        ]))
                    )
                    active = res_count.scalar_one() or 0
                    
                    if active == 0:
                        order.panel_id = panel.id
                        order.status = OrderStatus.ASSIGNED
                        order.assigned_at = datetime.utcnow()
                        await db.commit()
                        
                        result = await db.execute(select(Panel).where(Panel.id == panel.id))
                        order.panel = result.scalar_one_or_none()
                        await send_order_to_panel(order)
                        print(f"📦 Заказ #{order.id} назначен на Panel #{panel.panel_number}")
                        break
        except asyncio.CancelledError:
            break
        except Exception as e:
            print(f"❌ Ошибка: {e}")
            await asyncio.sleep(1)

@app.get("/", response_class=HTMLResponse)
async def admin_ui():
    return """
    <!DOCTYPE html>
    <html>
    <head>
        <title>AutoPanel Admin</title>
        <meta charset="utf-8">
        <style>
            body { font-family: -apple-system, sans-serif; max-width: 1000px; margin: 30px auto; padding: 20px; background: #f5f5f5; }
            h1 { color: #333; }
            .card { background: white; padding: 20px; border-radius: 8px; margin: 15px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
            input, button { padding: 10px 14px; margin: 5px; font-size: 15px; border-radius: 6px; border: 1px solid #ddd; }
            input { width: 180px; }
            button { background: #0066cc; color: white; border: none; cursor: pointer; font-weight: 500; }
            button:hover { background: #0052a3; }
            .panel { display: inline-block; padding: 10px 15px; margin: 5px; border-radius: 8px; background: #eee; font-weight: 500; }
            .panel.online { background: #d4edda; color: #155724; }
            .panel.offline { background: #f8d7da; color: #721c24; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
            th { background: #f8f9fa; }
            .status-queued { color: #888; }
            .status-assigned { color: #0066cc; font-weight: 500; }
            .status-waiting_weight { color: #f0ad4e; }
            .status-weight_detected { color: #5bc0de; }
            .status-completed { color: #5cb85c; }
            .mode-info { font-size: 14px; color: #666; padding: 12px; background: #e3f2fd; border-radius: 6px; margin-bottom: 20px; }
        </style>
    </head>
    <body>
        <h1>🚚 AutoPanel Admin</h1>
        <div class="mode-info" id="mode-info">Загрузка...</div>
        <div class="card">
            <h2>Создать заказ</h2>
            <input id="order_num" placeholder="Номер заказа">
            <input id="customer" placeholder="Имя клиента">
            <input id="panel_num" type="number" placeholder="Панель (1-24)" min="1" max="24">
            <button onclick="createOrder()">➕ Создать</button>
        </div>
        <div class="card">
            <h2>🟢 Панели</h2>
            <div id="panels">Загрузка...</div>
        </div>
        <div class="card">
            <h2>📋 Заказы</h2>
            <table id="orders">
                <thead><tr><th>ID</th><th>Номер</th><th>Клиент</th><th>Панель</th><th>Статус</th><th>Время</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <script>
            async function createOrder() {
                const order_number = document.getElementById('order_num').value || 'ORD-' + Date.now();
                const customer_name = document.getElementById('customer').value || 'Клиент';
                const panel_number = document.getElementById('panel_num').value ? parseInt(document.getElementById('panel_num').value) : null;
                const res = await fetch('/api/orders', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({order_number, customer_name, panel_number})
                });
                if (res.ok) {
                    document.getElementById('customer').value = '';
                    document.getElementById('order_num').value = '';
                    refresh();
                }
            }
            async function refresh() {
                try {
                    const sRes = await fetch('/api/status');
                    const status = await sRes.json();
                    document.getElementById('mode-info').innerHTML = 
                        `База: <b>${status.mode}</b> | Панелей онлайн: <b>${status.online_panels.length}</b>`;
                    
                    const pRes = await fetch('/api/panels');
                    const panels = await pRes.json();
                    document.getElementById('panels').innerHTML = panels.map(p => `
                        <div class="panel ${p.is_online ? 'online' : 'offline'}">
                            #${p.panel_number} ${p.is_online ? '🟢' : '⚫'}
                            ${p.active_orders > 0 ? '<b> (' + p.active_orders + ')</b>' : ' (свободна)'}
                            ${p.active_orders > 0 ? `<button onclick="resetPanel(${p.panel_number})" style="background:#dc3545; color:white; padding: 4px 8px; font-size: 12px; margin-left: 8px; border:none; border-radius: 4px; cursor:pointer;">Сброс</button>` : ''}
                        </div>
                    `).join('');
                    
                    const oRes = await fetch('/api/orders');
                    const orders = await oRes.json();
                    document.querySelector('#orders tbody').innerHTML = orders.map(o => `
                        <tr>
                            <td>${o.id}</td>
                            <td>${o.order_number}</td>
                            <td>${o.customer_name}</td>
                            <td>${o.panel_number ? '#' + o.panel_number : '-'}</td>
                            <td class="status-${o.status}">${o.status}</td>
                            <td>${new Date(o.created_at).toLocaleTimeString()}</td>
                        </tr>
                    `).join('');
                } catch (e) {
                    console.error('Refresh error:', e);
                }
            }
            refresh();
            setInterval(refresh, 1500);
            
            async function resetPanel(panelNumber) {
                if (!confirm(`Сбросить занятость панели #${panelNumber}?\nТекущий заказ будет помечен как ВЫПОЛНЕННЫЙ.`)) {
                    return;
                }
                try {
                    const res = await fetch(`/api/panels/${panelNumber}/reset`, { method: 'POST' });
                    const data = await res.json();
                    if (res.ok) {
                        alert(`✅ ${data.message}`);
                        refresh(); // Обновляем интерфейс
                    } else {
                        alert(`❌ Ошибка: ${data.detail || 'Неизвестная ошибка'}`);
                    }
                } catch (e) {
                    alert('Ошибка сети при сбросе');
                }
            }
        </script>
    </body>
    </html>
    """

if __name__ == "__main__":
    import uvicorn
    print("=" * 60)
    print("  AutoPanel Server (Docker + SQLite)")
    print("  http://0.0.0.0:8000")
    print("=" * 60)
    uvicorn.run(app, host="0.0.0.0", port=8000)
