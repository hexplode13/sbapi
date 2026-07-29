<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

class SyncJobService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function scheduleOrderTimesend(int $orderId): void
    {
        $stmt = $this->db->prepare("
            SELECT
                id,
                token,
                time_start,
                time_end,
                date_start,
                date_end,
                sended
            FROM my_orders
            WHERE id = ?
        ");

        $stmt->execute([$orderId]);

        $order = $stmt->fetch();

        if (!$order) {
            return;
        }

        $ready =
            !empty($order['token']) &&
            empty($order['sended']) &&
            !empty($order['time_start']) &&
            !empty($order['time_end']) &&
            !empty($order['date_start']) &&
            !empty($order['date_end']);

        if (!$ready) {
            return;
        }

        $this->push(
            type: 'order.timesend',
            payload: [
                'order_id' => $orderId,
            ],
            dedupKey: 'order.timesend:' . $orderId
        );
    }

    public function push(string $type, array $payload, ?string $dedupKey = null): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO sync_jobs
                (type, payload, status, attempts, max_attempts, available_at, dedup_key, created_at, updated_at)
            VALUES
                (
                    :type,
                    :payload,
                    'pending',
                    0,
                    5,
                    NOW(),
                    :dedup_key,
                    NOW(),
                    NOW()
                )
            ON DUPLICATE KEY UPDATE
                payload = VALUES(payload),
                status = IF(status = 'done', status, 'pending'),
                attempts = IF(status = 'done', attempts, 0),
                available_at = NOW(),
                updated_at = NOW()
        ");

        $stmt->execute([
            'type' => $type,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'dedup_key' => $dedupKey,
        ]);
    }
}