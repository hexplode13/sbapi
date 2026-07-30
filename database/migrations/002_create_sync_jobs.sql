CREATE TABLE IF NOT EXISTS sync_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type VARCHAR(64) NOT NULL,
    payload JSON NULL,
    status ENUM('pending', 'processing', 'done', 'failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reserved_at DATETIME NULL,
    last_error TEXT NULL,
    dedup_key VARCHAR(190) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_sync_jobs_dedup_key (dedup_key),
    KEY idx_sync_jobs_status_available (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Поля для фоновой отправки на внешний сервер

ALTER TABLE my_orders ADD COLUMN IF NOT EXISTS sended INT NULL;
ALTER TABLE my_orders ADD COLUMN IF NOT EXISTS sync_attempts INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE my_orders ADD COLUMN IF NOT EXISTS sync_last_error TEXT NULL;
ALTER TABLE my_orders ADD COLUMN IF NOT EXISTS sync_next_attempt_at DATETIME NULL;

ALTER TABLE my_orders ADD INDEX IF NOT EXISTS idx_my_orders_sync_pending (sended, sync_next_attempt_at);