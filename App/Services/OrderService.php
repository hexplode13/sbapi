<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

class OrderService
{
    private PDO $db;
    private SyncJobService $syncJobs;

    public function __construct()
    {
        $this->db = Database::pdo();
        $this->syncJobs = new SyncJobService();
    }

    public function getOrders(bool $kuch = false, ?string $date = null, bool $onlyTables = false): array
    {
        if ($date === null || $date === '') {
            $stmt = $this->db->query("SELECT MAX(date) AS d FROM my_orders LIMIT 1");
            $row = $stmt->fetch();

            $date = $row['d'] ?? date('Y-m-d');
        }

        if (!$kuch) {
            $sql = "
                SELECT
                    o.*,
                    (
                        SELECT k.status
                        FROM my_orders_kuch k
                        WHERE k.id = o.id
                    ) AS kustatus
                FROM my_orders o
                WHERE o.date = :date
            ";

            if ($onlyTables) {
                $sql .= " AND o.status < 2 AND o.table > 0";
            } else {
                $sql .= " AND o.status < 3";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'date' => $date,
            ]);

            $headers = $stmt->fetchAll();
        } else {
            $stmt = $this->db->prepare("
                SELECT
                    k.*,
                    0 AS `table`,
                    NULL AS kustatus
                FROM my_orders_kuch k
                WHERE k.date = :date
                  AND k.status < 2
            ");

            $stmt->execute([
                'date' => $date,
            ]);

            $headers = $stmt->fetchAll();
        }

        if (!$headers) {
            return [];
        }

        $ids = array_map(
            static fn(array $row): int => (int)$row['id'],
            $headers
        );

        $items = $this->getItemsByOrders($ids, $kuch);

        $orders = [];

        foreach ($headers as $header) {
            $outid = (string)($header['outid'] ?? '');

            $shortId = strlen($outid) > 3
                ? substr($outid, -3)
                : $outid;

            $orders[] = [
                'inid' => (int)$header['id'],
                'table' => (int)($header['table'] ?? 0),
                'id' => $shortId,
                'status' => (int)($header['status'] ?? 0),
                'kustatus' => isset($header['kustatus']) ? (int)$header['kustatus'] : null,
                'header' => [
                    'date' => $header['date'] ?? null,
                    'time' => $header['time'] ?? null,
                ],
                'items' => $items[(int)$header['id']] ?? [],
            ];
        }

        return $orders;
    }

    private function getItemsByOrders(array $orderIds, bool $kuchOnly = false): array
    {
        if (!$orderIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

        $sql = "
            SELECT *
            FROM my_orders_items
            WHERE `order` IN ($placeholders)
        ";

        $params = $orderIds;

        if ($kuchOnly) {
            $sql .= " AND kuch = 1";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $items = [];

        foreach ($stmt->fetchAll() as $item) {
            $items[(int)$item['order']][] = $item;
        }

        return $items;
    }

    public function create(array $data): array
    {
        if (!isset($data['date'], $data['time'], $data['id'], $data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('Invalid order data');
        }

        if (!$data['items']) {
            throw new RuntimeException('Order items are empty');
        }

        $date = (string)$data['date'];
        $time = (string)$data['time'];
        $outid = (string)$data['id'];
        $point = (string)($data['point'] ?? '-1');
        $comment = (string)($data['comment'] ?? '');
        $token = (string)($data['token'] ?? '');
        $techCard = (int)($data['tc'] ?? 0);

        $startDate = date('Y-m-d');
        $startTime = date('H:i:s');

        $hash = md5($date . $time . $outid . $point);

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                INSERT INTO my_orders
                    (outid, date, time, comment, status, point, uniq, date_start, time_start, token)
                VALUES
                    (:outid, :date, :time, :comment, 0, :point, :uniq, :date_start, :time_start, :token)
            ");

            $stmt->execute([
                'outid' => $outid,
                'date' => $date,
                'time' => $time,
                'comment' => $comment,
                'point' => $point,
                'uniq' => $hash,
                'date_start' => $startDate,
                'time_start' => $startTime,
                'token' => $token,
            ]);

            $orderId = (int)$this->db->lastInsertId();

            $itemStmt = $this->db->prepare("
                INSERT INTO my_orders_items
                    (name, count, `order`, comment, kuch, done, start_date, start_time, tech_card)
                VALUES
                    (:name, :count, :order, :comment, :kuch, 0, :start_date, :start_time, :tech_card)
            ");

            $hasKuch = false;

            foreach ($data['items'] as $item) {
                if (!isset($item['name'], $item['count'])) {
                    continue;
                }

                $kuch = (int)($item['kuch'] ?? 0);

                if ($kuch === 1) {
                    $hasKuch = true;
                }

                $itemStmt->execute([
                    'name' => (string)$item['name'],
                    'count' => (string)$item['count'],
                    'order' => $orderId,
                    'comment' => (string)($item['comments'] ?? ''),
                    'kuch' => $kuch,
                    'start_date' => $startDate,
                    'start_time' => $startTime,
                    'tech_card' => $techCard,
                ]);
            }

            if ($hasKuch) {
                $kuchStmt = $this->db->prepare("
                    INSERT INTO my_orders_kuch
                        (id, outid, date, time, comment, status)
                    VALUES
                        (:id, :outid, :date, :time, :comment, 0)
                ");

                $kuchStmt->execute([
                    'id' => $orderId,
                    'outid' => $outid,
                    'date' => $date,
                    'time' => $time,
                    'comment' => $comment,
                ]);
            }

            $this->db->commit();

            return [
                'id' => $orderId,
                'outid' => $outid,
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    public function close(int $orderId, bool $onTable = false): void
    {
        $time = date('H:i:s');
        $date = date('Y-m-d');

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                UPDATE my_orders
                SET status = 2
                WHERE id = ?
            ");
            $stmt->execute([$orderId]);

            $stmt = $this->db->prepare("
                UPDATE my_orders_kuch
                SET status = 2
                WHERE id = ?
            ");
            $stmt->execute([$orderId]);

            if ($onTable) {
                $stmt = $this->db->prepare("
                    UPDATE tables
                    SET `order` = 0
                    WHERE `order` = ?
                ");
                $stmt->execute([$orderId]);
            }

            $stmt = $this->db->prepare("
                UPDATE my_orders_items
                SET
                    done = 1,
                    end_time = :end_time,
                    end_date = :end_date
                WHERE `order` = :order_id
                  AND (
                        end_time IS NULL
                     OR end_date IS NULL
                     OR end_time = ''
                     OR end_date = ''
                  )
            ");

            $stmt->execute([
                'end_time' => $time,
                'end_date' => $date,
                'order_id' => $orderId,
            ]);

            $this->db->commit();

            $this->syncJobs->scheduleOrderTimesend($orderId);
        } catch (Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    public function update(int $orderId, bool $onTable = false): array
    {
        $time = date('H:i:s');
        $date = date('Y-m-d');

        $this->db->beginTransaction();

        try {
            $table = null;

            if ($onTable) {
                $table = $this->findFreeTableForUpdate();

                if ($table === null) {
                    $this->db->rollBack();

                    return [
                        'order' => '-1',
                        'info' => 'Not have free table!',
                    ];
                }
            }

            if ($onTable && $table !== null) {
                $stmt = $this->db->prepare("
                    UPDATE my_orders
                    SET
                        status = 1,
                        time_end = :time_end,
                        date_end = :date_end,
                        `table` = :table
                    WHERE id = :id
                ");

                $stmt->execute([
                    'time_end' => $time,
                    'date_end' => $date,
                    'table' => $table,
                    'id' => $orderId,
                ]);

                $stmt = $this->db->prepare("
                    UPDATE tables
                    SET `order` = :order_id
                    WHERE table_no = :table_no
                ");

                $stmt->execute([
                    'order_id' => $orderId,
                    'table_no' => $table,
                ]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE my_orders
                    SET
                        status = 1,
                        time_end = :time_end,
                        date_end = :date_end
                    WHERE id = :id
                ");

                $stmt->execute([
                    'time_end' => $time,
                    'date_end' => $date,
                    'id' => $orderId,
                ]);
            }

            $stmt = $this->db->prepare("
                UPDATE my_orders_items
                SET
                    done = 1,
                    end_time = :end_time,
                    end_date = :end_date
                WHERE `order` = :order_id
                  AND (
                        end_time IS NULL
                     OR end_date IS NULL
                     OR end_time = ''
                     OR end_date = ''
                  )
            ");

            $stmt->execute([
                'end_time' => $time,
                'end_date' => $date,
                'order_id' => $orderId,
            ]);

            $stmt = $this->db->prepare("
                UPDATE my_orders_kuch
                SET
                    status = 1,
                    time_end = :time_end,
                    date_end = :date_end
                WHERE id = :id
            ");

            $stmt->execute([
                'time_end' => $time,
                'date_end' => $date,
                'id' => $orderId,
            ]);

            $this->db->commit();

            $this->syncJobs->scheduleOrderTimesend($orderId);

            return [
                'order' => $orderId,
                'table' => $table,
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    private function findFreeTableForUpdate(): ?int
    {
        $stmt = $this->db->query("
            SELECT table_no
            FROM tables
            WHERE `order` = 0
            ORDER BY table_no ASC
            LIMIT 1
            FOR UPDATE
        ");

        $row = $stmt->fetch();

        return $row ? (int)$row['table_no'] : null;
    }

    public function send(int $orderId): void
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                UPDATE my_orders
                SET status = 3
                WHERE id = ?
            ");
            $stmt->execute([$orderId]);

            $stmt = $this->db->prepare("
                UPDATE my_orders_kuch
                SET status = 3
                WHERE id = ?
            ");
            $stmt->execute([$orderId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    public function closePosition(int $positionId): void
    {
        $time = date('H:i:s');
        $date = date('Y-m-d');

        $stmt = $this->db->prepare("
            UPDATE my_orders_items
            SET
                done = 1,
                end_time = :end_time,
                end_date = :end_date
            WHERE id = :id
        ");

        $stmt->execute([
            'end_time' => $time,
            'end_date' => $date,
            'id' => $positionId,
        ]);
    }

    public function closeKuch(int $orderId): void
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                UPDATE my_orders_kuch
                SET status = 2
                WHERE id = ?
            ");
            $stmt->execute([$orderId]);

            $stmt = $this->db->prepare("
                UPDATE my_orders_items
                SET done = 1
                WHERE `order` = ?
                  AND kuch = 1
            ");
            $stmt->execute([$orderId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }
}