-- Добавляем поля для интеграции с сервером панелей
ALTER TABLE my_orders 
ADD COLUMN  IF NOT EXISTS panel_number INT NULL AFTER token,
ADD COLUMN  IF NOT EXISTS panel_sent TINYINT(1) DEFAULT 0 AFTER panel_number;

-- Индекс для быстрого поиска неотправленных заказов
ALTER TABLE my_orders ADD INDEX IF NOT EXISTS idx_my_orders_panel_sent (panel_sent);