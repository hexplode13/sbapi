ALTER TABLE my_orders
    ADD INDEX IF NOT EXISTS idx_my_orders_date_status (date, status),
    ADD INDEX IF NOT EXISTS idx_my_orders_token_sended (token, sended),
    ADD INDEX IF NOT EXISTS idx_my_orders_table (`table`);

ALTER TABLE my_orders_items
    ADD INDEX IF NOT EXISTS idx_items_order (`order`),
    ADD INDEX IF NOT EXISTS idx_items_kuch (kuch),
    ADD INDEX IF NOT EXISTS idx_items_done (done);

ALTER TABLE my_orders_kuch
    ADD INDEX IF NOT EXISTS idx_kuch_date_status (date, status);

ALTER TABLE tables
    ADD INDEX IF NOT EXISTS idx_tables_order (`order`);