import asyncio
import anyio
import json
from enum import Enum
import logging
import os
import secrets
from contextlib import asynccontextmanager, suppress
from datetime import datetime
from typing import Optional
from fastapi import FastAPI, WebSocket, WebSocketDisconnect, HTTPException, Query, Request
from fastapi.responses import HTMLResponse, Response
from pydantic import BaseModel, Field
from pathlib import Path

class OrderStatus(str, Enum):
    QUEUED = 'queued'
    ASSIGNED = 'assigned'
    WAITING_WEIGHT = 'waiting_weight'
    WEIGHT_DETECTED = 'weight_detected'
    COMPLETED = 'completed'
    CANCELLED = 'cancelled'

logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
log = logging.getLogger('autopanel')
active_connections = {}
panel_runtime = {}
state_lock = asyncio.Lock()
ACTIVE = (OrderStatus.ASSIGNED, OrderStatus.WAITING_WEIGHT, OrderStatus.WEIGHT_DETECTED)

DATA_DIR = Path(os.getenv('DB_DIR', '/app/data'))
panels = {}

def save_panel(number, panel):
    path = DATA_DIR / f'panel-{number}.json'
    temporary = path.with_suffix('.tmp')
    with temporary.open('w', encoding='utf-8') as stream:
        json.dump(panel, stream, ensure_ascii=False, indent=2)
        stream.flush()
        os.fsync(stream.fileno())
    temporary.replace(path)
    panels[number] = panel

@asynccontextmanager
async def lifespan(app):
    global state_lock
    state_lock = asyncio.Lock()
    active_connections.clear()
    panel_runtime.clear()
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    panels.clear()
    for number in range(1, 25):
        path = DATA_DIR / f'panel-{number}.json'
        if path.exists():
            panels[number] = json.loads(path.read_text(encoding='utf-8'))
    log.info('server_start pid=%s', os.getpid())
    yield
    log.info('server_stop')

app = FastAPI(title='AutoPanel Server 5.12', lifespan=lifespan)

@app.middleware('http')
async def auth(request: Request, call_next):
    password = os.getenv('ADMIN_PASSWORD', '')
    if password:
        import base64
        expected = 'Basic ' + base64.b64encode((os.getenv('ADMIN_USER', 'admin') + ':' + password).encode()).decode()
        if not secrets.compare_digest(request.headers.get('authorization', ''), expected):
            return Response(status_code=401, headers={'WWW-Authenticate': 'Basic realm="AutoPanel"'})
    return await call_next(request)

class OrderCreate(BaseModel):
    order_number: str = Field(min_length=1, max_length=50)
    customer_name: str = Field(min_length=1, max_length=100)
    panel_number: Optional[int] = Field(default=None, ge=1, le=24)

def active_order(number):
    order = panels.get(number, {}).get('order')
    return order if order and order['status'] in ACTIVE else None

async def send(number, payload):
    ws = active_connections.get(number)
    if not ws:
        return False
    try:
        await asyncio.wait_for(ws.send_json(payload), timeout=3)
        return True
    except Exception:
        log.exception('send_failed panel=%s', number)
        panel_runtime.get(number, {})['ready'] = False
        with suppress(Exception):
            await ws.close(code=1011)
        return False

async def deliver(number, order):
    ok = await send(number, dict(id=order['id'], order_number=order['order_number'], name=order['customer_name'], status='wait'))
    log.info('order_delivery panel=%s order=%s sent=%s', number, order['id'], ok)
    return ok

@app.websocket('/ws/panel/{panel_number}')
async def panel_websocket(websocket: WebSocket, panel_number: int):
    key = os.getenv('PANEL_API_KEY', '')
    if not 1 <= panel_number <= 24 or (key and not secrets.compare_digest(websocket.headers.get('x-panel-key', ''), key)):
        await websocket.close(code=1008)
        return
    await websocket.accept()
    async with state_lock:
        previous = active_connections.get(panel_number)
        active_connections[panel_number] = websocket
        panel_runtime[panel_number] = {'ready': False, 'state': 'connecting', 'diagnostics': {}}
        panel = panels.get(panel_number, {'name': None, 'order': None})
        try:
            save_panel(panel_number, dict(panel, last_seen=datetime.utcnow().isoformat() + 'Z'))
        except OSError:
            active_connections.pop(panel_number, None)
            panel_runtime.pop(panel_number, None)
            log.exception('panel_storage_failed panel=%s', panel_number)
            await websocket.close(code=1011)
            return
        if previous:
            with suppress(Exception):
                await previous.close(code=1000)
    log.info('panel_connected panel=%s peer=%s', panel_number, websocket.client)
    try:
        while True:
            raw = await websocket.receive_text()
            try:
                message = json.loads(raw)
                if not isinstance(message, dict):
                    continue
                async with state_lock:
                    if active_connections.get(panel_number) is not websocket:
                        break
                    await handle_panel_message(panel_number, message)
            except (json.JSONDecodeError, ValueError, TypeError):
                log.warning('invalid_message panel=%s data=%r', panel_number, raw[:250])
    except WebSocketDisconnect as exc:
        log.info('panel_disconnected panel=%s code=%s', panel_number, exc.code)
    except Exception:
        log.exception('panel_connection_error panel=%s', panel_number)
    finally:
        with anyio.CancelScope(shield=True):
            async with state_lock:
                if active_connections.get(panel_number) is websocket:
                    active_connections.pop(panel_number, None)
                    panel_runtime.pop(panel_number, None)

async def handle_panel_message(number, message):
    runtime = panel_runtime[number]
    status = message.get('status')
    runtime['last_seen'] = datetime.utcnow().isoformat() + 'Z'
    if not isinstance(status, str):
        return
    if status == 'heartbeat':
        if message.get('sensor_ok') is False:
            runtime['ready'] = False
        runtime['diagnostics'] = {k: message.get(k) for k in ('uptime_ms', 'free_heap', 'min_heap', 'rssi', 'reset_reason', 'fw', 'sensor_ok', 'state')}
        log.info('heartbeat panel=%s data=%s', number, runtime['diagnostics'])
        return
    log.info('panel_status panel=%s message=%s', number, message)
    order = active_order(number)
    if status == 'idle':
        if runtime.get('reset_pending'):
            if order:
                update_order(number, order, OrderStatus.CANCELLED)
            runtime.pop('reset_pending', None)
            order = None
        runtime.update(state='idle', ready=order is None and runtime.get('diagnostics', {}).get('sensor_ok') is not False)
        if order and runtime.get('diagnostics', {}).get('sensor_ok') is not False:
            update_order(number, order, OrderStatus.ASSIGNED)
            await deliver(number, order)
            return
    elif status == 'ota_ack':
        runtime.update(ready=False, state='ota')
    elif status in ('ota_rejected_busy', 'ota_rejected_storage'):
        runtime.update(ready=False, state=status)
    elif status == 'factory_reset_ack':
        runtime.update(ready=False, state='setup')
        if order:
            update_order(number, order, OrderStatus.CANCELLED)
    elif status == 'sensor_or_order_error':
        runtime.update(ready=False, state='sensor_error')
    else:
        order_id = message.get('order_id')
        if not order or type(order_id) is not int or order_id != order['id']:
            return
        transitions = {'waiting_for_weight': OrderStatus.WAITING_WEIGHT, 'weight_detected': OrderStatus.WEIGHT_DETECTED, 'completed': OrderStatus.COMPLETED, 'done': OrderStatus.COMPLETED, 'timeout_reset': OrderStatus.CANCELLED}
        target = transitions.get(status)
        ranks = {OrderStatus.ASSIGNED: 0, OrderStatus.WAITING_WEIGHT: 1, OrderStatus.WEIGHT_DETECTED: 2, OrderStatus.COMPLETED: 3, OrderStatus.CANCELLED: 3}
        if target and ranks[target] >= ranks[order['status']]:
            update_order(number, order, target)
            runtime.update(ready=False, state=status)


def update_order(number, order, status):
    updated = dict(order, status=status)
    if status not in ACTIVE:
        updated['completed_at'] = datetime.utcnow().isoformat() + 'Z'
    panel = dict(panels[number], order=updated, last_seen=panel_runtime[number]['last_seen'])
    save_panel(number, panel)

@app.post('/api/orders')
async def create_order(order: OrderCreate):
    async with state_lock:
        candidates = [order.panel_number] if order.panel_number else sorted(active_connections)
        number = next((n for n in candidates if n in active_connections
                       and panel_runtime[n]['ready'] and not active_order(n)), None)
        if number is None:
            raise HTTPException(409, 'Нет свободной готовой панели. Заказ не сохранён')
        next_id = max((p['order']['id'] for p in panels.values() if p.get('order')), default=0) + 1
        item = dict(id=next_id, order_number=order.order_number, customer_name=order.customer_name,
                    status=OrderStatus.ASSIGNED, panel_number=number,
                    created_at=datetime.utcnow().isoformat() + 'Z')
        previous = panels[number]
        try:
            save_panel(number, dict(previous, order=item))
        except OSError:
            log.exception('order_storage_failed panel=%s', number)
            raise HTTPException(503, 'Не удалось записать состояние панели')
        panel_runtime[number]['ready'] = False
        if not await deliver(number, item):
            save_panel(number, dict(previous, order=dict(item, status=OrderStatus.CANCELLED)))
            raise HTTPException(503, 'Не удалось отправить заказ')
        return dict(status='ok', order_id=item['id'], panel_number=number, message='Заказ назначен')

@app.get('/api/orders')
async def list_orders(status: Optional[OrderStatus] = None, limit: int = Query(50, ge=1, le=100)):
    orders = [p['order'] for p in panels.values() if p.get('order')]
    return sorted((o for o in orders if status is None or o['status'] == status),
                  key=lambda o: o['id'], reverse=True)[:limit]

@app.get('/api/panels')
async def list_panels():
    result = []
    for number, panel in sorted(panels.items()):
        runtime = panel_runtime.get(number, {})
        result.append(dict(panel_number=number, name=panel['name'], is_online=number in active_connections,
                           last_seen=runtime.get('last_seen', panel.get('last_seen')),
                           active_orders=int(active_order(number) is not None),
                           ready=runtime.get('ready', False), diagnostics=runtime.get('diagnostics', {})))
    return result

@app.get('/api/panels/free')
async def free_panels():
    panels = [p for p in await list_panels() if p['is_online'] and p['ready'] and not p['active_orders']]
    return {'count': len(panels), 'free_panels': panels}

@app.post('/api/panels/{panel_number}/ota', status_code=202)
async def enter_ota(panel_number: int):
    async with state_lock:
        if panel_number not in active_connections:
            raise HTTPException(404, 'Панель отключена')
        runtime = panel_runtime[panel_number]
        if not runtime.get('ready'):
            raise HTTPException(409, 'Панель занята или ещё не готова')
        # The old firmware does not implement enter_ota: do not reserve it forever.
        if runtime.get('diagnostics', {}).get('fw') not in ('5.10', '5.11'):
            raise HTTPException(409, 'Для перехода в OTA нужна прошивка 5.10 или 5.11')
        if active_order(panel_number):
            raise HTTPException(409, 'У панели есть активный заказ')
        runtime.update(ready=False, state='ota_pending')
        if not await send(panel_number, {'command': 'enter_ota'}):
            raise HTTPException(503, 'Не удалось отправить команду')
        return {'status': 'pending', 'message': 'Переход в OTA запрошен. После перезагрузки откройте http://IP_ПАНЕЛИ/update'}

@app.post('/api/panels/{panel_number}/reset')
async def reset_panel(panel_number: int):
    async with state_lock:
        if panel_number not in active_connections:
            raise HTTPException(404, 'Панель отключена')
        if not await send(panel_number, {'command': 'reset'}):
            raise HTTPException(503, 'Не удалось отправить команду')
        panel_runtime[panel_number].update(ready=False, reset_pending=True)
        return {'status': 'ok', 'message': 'Сброс отправлен; ожидается подтверждение панели'}

@app.post('/api/panels/{panel_number}/factory-reset')
async def factory_reset_panel(panel_number: int):
    async with state_lock:
        if panel_number not in active_connections:
            raise HTTPException(404, 'Панель отключена')
        if not await send(panel_number, {'command': 'factory_reset'}):
            raise HTTPException(503, 'Не удалось отправить команду')
        panel_runtime[panel_number]['ready'] = False
        return {'status': 'ok', 'message': 'Отправлена команда перехода в настройку'}

@app.get('/api/status')
async def server_status():
    return {'mode': 'json', 'version': '5.12', 'online_panels': list(active_connections), 'server_time': datetime.utcnow().isoformat() + 'Z'}
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
                } else {
                    const err = await res.json();
                    alert('Ошибка: ' + JSON.stringify(err.detail || err));
                }
            }
            function esc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
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
                            ${p.active_orders > 0 ? '<b> (' + p.active_orders + ')</b>' : (p.ready ? ' (готова)' : ' (не готова)')}<br><small>${esc(JSON.stringify(p.diagnostics))}</small>
                            ${p.active_orders > 0 ? `<button onclick="resetPanel(${p.panel_number})" style="background:#dc3545; color:white; padding: 4px 8px; font-size: 12px; margin-left: 8px; border:none; border-radius: 4px; cursor:pointer;">Сброс</button>` : ''}
                        </div>
                    `).join('');
                    
                    const oRes = await fetch('/api/orders');
                    const orders = await oRes.json();
                    document.querySelector('#orders tbody').innerHTML = orders.map(o => `
                        <tr>
                            <td>${o.id}</td>
                            <td>${esc(o.order_number)}</td>
                            <td>${esc(o.customer_name)}</td>
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
                if (!confirm(`Сбросить занятость панели #${panelNumber}?\nТекущий заказ будет отменён после подтверждения панели.`)) {
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

