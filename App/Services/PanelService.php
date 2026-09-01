<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

class PanelService
{
    private string $baseUrl;
    private int $timeout;
    private PDO $db;

    public function __construct()
    {
        $config = require __DIR__ . '/../../config/config.php';
        
        $this->baseUrl = rtrim($config['panel_server']['base_url'] ?? 'http://192.168.0.213:8000', '/');
        $this->timeout = (int)($config['panel_server']['timeout'] ?? 3);
        
        $dbConfig = $config['db'];
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbConfig['host'], $dbConfig['name'], $dbConfig['charset']);
        
        $this->db = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Пытается найти свободную панель и отправить на нее заказ.
     * Возвращает номер панели, если успешно, или null, если свободных нет или ошибка.
     */
    public function tryAssignAndSendOrder(string $orderNumber, string $customerName): ?int
    {
        try {
            $freePanels = $this->getFreePanels();

            if (empty($freePanels)) {
                // Свободных панелей нет, просто выходим (как и требовалось)
                return null;
            }

            // Берем первую свободную панель
            $panelNumber = (int)$freePanels[0]['panel_number'];

            $success = $this->sendOrderToPanel($orderNumber, $customerName, $panelNumber);

            if ($success) {
                return $panelNumber;
            }

            return null;
        } catch (Throwable $e) {
            // Логируем ошибку, но не прерываем основной процесс создания/обновления заказа
            error_log('PanelService error: ' . $e->getMessage());
            return null;
        }
    }

    private function getFreePanels(): array
    {
        $url = $this->baseUrl . '/api/panels/free';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            return $data['free_panels'] ?? [];
        }

        return [];
    }

    private function sendOrderToPanel(string $orderNumber, string $customerName, int $panelNumber): bool
    {
        $url = $this->baseUrl . '/api/orders';
        $payload = json_encode([
            'order_number' => $orderNumber,
            'customer_name' => $customerName,
            'panel_number' => $panelNumber,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 || $httpCode === 201) {
            $data = json_decode($response, true);
            return ($data['status'] ?? '') === 'ok';
        }

        return false;
    }

    /**
     * Сохраняет факт отправки в БД
     */
    public function markOrderAsSent(int $orderId, int $panelNumber): void
    {
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
    }
}