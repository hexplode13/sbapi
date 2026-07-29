ALTER TABLE my_orders
    ADD INDEX idx_my_orders_date_status (date, status),
    ADD INDEX idx_my_orders_token_sended (token, sended),
    ADD INDEX idx_my_orders_table (`table`);

ALTER TABLE my_orders_items
    ADD INDEX idx_items_order (`order`),
    ADD INDEX idx_items_kuch (kuch),
    ADD INDEX idx_items_done (done);

ALTER TABLE my_orders_kuch
    ADD INDEX idx_kuch_date_status (date, status);

ALTER TABLE tables
    ADD INDEX idx_tables_order (`order`);