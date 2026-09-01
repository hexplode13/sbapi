<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

class PanelService
{
    private string $baseUrl;
    private int $timeout;
    private PDO $db;
    private string $logFile;

    public function __construct()
    {
        $config = require __DIR__ . '/../../config/config.php';

        $this->baseUrl = rtrim($config['panel_server']['base_url'] ?? 'http://192.168.0.213:8000', '/');
        $this->timeout = (int)($config['panel_server']['timeout'] ?? 5);

        // Используем ОБЩЕЕ подключение к БД
        $this->db = Database::pdo();

        // Путь к отдельному логу для панелей
        $this->logFile = __DIR__ . '/../../storage/logs/panel.log';
    }

    private function log(string $message): void
    {
        $line = sprintf(
            "[%s] %s%s",
            date('Y-m-d H:i:s'),
            $message,
            PHP_EOL
        );

        @file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    /**
     * Пытается найти свободную панель и отправить на нее заказ.
     * Возвращает номер панели, если успешно, или null, если свободных нет или ошибка.
     */
    public function tryAssignAndSendOrder(string $orderNumber, string $customerName): ?int
    {
        $this->log("=== Start tryAssignAndSendOrder: order_number={$orderNumber}, customer={$customerName} ===");

        try {
            // 1. Получаем список свободных панелей
            $this->log("Requesting free panels from: {$this->baseUrl}/api/panels/free");
            $freePanels = $this->getFreePanels();

            if (empty($freePanels)) {
                $this->log("No free panels available. Aborting.");
                return null;
            }

            $this->log("Found " . count($freePanels) . " free panel(s): " . json_encode($freePanels));

            // 2. Берем первую свободную панель
            $panelNumber = (int)$freePanels[0]['panel_number'];
            $this->log("Selected panel_number: {$panelNumber}");

            // 3. Отправляем заказ на панель
            $success = $this->sendOrderToPanel($orderNumber, $customerName, $panelNumber);

            if ($success) {
                $this->log("Successfully sent to panel {$panelNumber}");
                return $panelNumber;
            }

            $this->log("Failed to send to panel {$panelNumber}");
            return null;
        } catch (Throwable $e) {
            $this->log("ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return null;
        }
    }

    private function getFreePanels(): array
    {
        $url = $this->baseUrl . '/api/panels/free';

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Cannot init cURL for getFreePanels');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $this->log("GET /api/panels/free -> HTTP {$httpCode}, errno={$errno}, error={$error}");

        if ($errno !== 0) {
            throw new RuntimeException("cURL error: {$error}");
        }

        if ($httpCode !== 200) {
            throw new RuntimeException("HTTP {$httpCode}, body: " . (is_string($response) ? $response : ''));
        }

        if (!$response) {
            return [];
        }

        $data = json_decode($response, true);
        $this->log("Free panels response: " . $response);

        return $data['free_panels'] ?? [];
    }

    private function sendOrderToPanel(string $orderNumber, string $customerName, int $panelNumber): bool
    {
        $url = $this->baseUrl . '/api/orders';
        $payload = json_encode([
            'order_number' => $orderNumber,
            'customer_name' => $customerName,
            'panel_number' => $panelNumber,
        ], JSON_UNESCAPED_UNICODE);

        $this->log("POST /api/orders payload: {$payload}");

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Cannot init cURL for sendOrderToPanel');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $this->log("POST /api/orders -> HTTP {$httpCode}, errno={$errno}, error={$error}, body: " . (is_string($response) ? $response : ''));

        if ($errno !== 0) {
            throw new RuntimeException("cURL error: {$error}");
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new RuntimeException("HTTP {$httpCode}, body: " . (is_string($response) ? $response : ''));
        }

        $data = json_decode($response, true);
        $result = ($data['status'] ?? '') === 'ok';

        $this->log("Send result: " . ($result ? 'OK' : 'FAILED'));

        return $result;
    }

    /**
     * Сохраняет факт отправки в БД
     */
    public function markOrderAsSent(int $orderId, int $panelNumber): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE my_orders
                SET panel_number = :panel_number,
                    panel_sent = 1
                WHERE id = :id
            ");
            $stmt->execute([
                'panel_number' => $panelNumber,
                'id' => $orderId,
            ]);
            $this->log("DB updated: order_id={$orderId}, panel_number={$panelNumber}");
        } catch (Throwable $e) {
            $this->log("DB update ERROR: " . $e->getMessage());
        }
    }
}