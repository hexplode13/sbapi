<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

class ExternalApiService
{
    private PDO $db;
    private array $config;

    public function __construct()
    {
        $this->db = Database::pdo();

        $config = require __DIR__ . '/../../config/config.php';

        $this->config = $config['external'];
    }

    public function sendOrderTimesend(int $orderId): void
    {
        $stmt = $this->db->prepare("
            SELECT
                id,
                outid,
                date,
                time,
                uniq,
                point,
                token,
                time_start,
                time_end,
                date_start,
                date_end
            FROM my_orders
            WHERE id = ?
        ");

        $stmt->execute([$orderId]);

        $order = $stmt->fetch();

        if (!$order) {
            return;
        }

        if (empty($order['token'])) {
            return;
        }

        $items = $this->getOrderItems($orderId);

        $request = [
            'items' => $items,
            'place' => $order['point'],
        ];

        $response = $this->postJson(
            url: $this->config['base_url'] . '/timesend/',
            token: (string)$order['token'],
            data: $request
        );

        $decoded = json_decode($response, true);

        $rowsCount = $decoded['data'][0]['rows_count'] ?? null;

        if ($rowsCount !== null && $rowsCount !== '0' && $rowsCount !== 0) {
            $stmt = $this->db->prepare("
                UPDATE my_orders
                SET sended = 1
                WHERE id = ?
            ");

            $stmt->execute([$orderId]);
        }
    }

    private function getOrderItems(int $orderId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                outid AS uniqid,
                name,
                count,
                start_time,
                end_time,
                start_date,
                end_date,
                0 AS tech_card
            FROM my_orders_items
            WHERE `order` = ?
              AND start_time != ''
              AND end_time != ''
              AND start_date != ''
              AND end_date != ''
        ");

        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }

    private function postJson(string $url, string $token, array $data): string
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Cannot init cURL');
        }

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => $this->config['timeout'] ?? 5,
            CURLOPT_CONNECTTIMEOUT => $this->config['connect_timeout'] ?? 2,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $result = curl_exec($ch);

        $errno = curl_errno($ch);
        $error = curl_error($ch);

        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('cURL error: ' . $error);
        }

        if ($statusCode >= 400) {
            throw new RuntimeException('External API HTTP error: ' . $statusCode);
        }

        return is_string($result) ? $result : '';
    }
}