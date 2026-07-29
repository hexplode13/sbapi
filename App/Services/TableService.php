<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

class TableService
{
    private PDO $db;
    private OrderService $orders;

    public function __construct()
    {
        $this->db = Database::pdo();
        $this->orders = new OrderService();
    }

    public function setActive(int $table, int $active): void
    {
        $stmt = $this->db->prepare("
            UPDATE tables
            SET active = ?
            WHERE table_no = ?
        ");

        $stmt->execute([$active, $table]);
    }

    public function clearTable(int $table): array
    {
        $stmt = $this->db->prepare("
            SELECT `order`
            FROM tables
            WHERE table_no = ?
        ");

        $stmt->execute([$table]);

        $row = $stmt->fetch();

        if (!$row || empty($row['order'])) {
            return [
                'order' => 0,
                'info' => 'Table is empty',
            ];
        }

        $orderId = (int)$row['order'];

        $this->orders->close($orderId, true);

        $stmt = $this->db->prepare("
            UPDATE tables
            SET `order` = 0,
                active = 0
            WHERE table_no = ?
        ");

        $stmt->execute([$table]);

        return [
            'order' => $orderId,
            'table' => $table,
        ];
    }
}