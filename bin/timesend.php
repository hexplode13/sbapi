#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Smartbar timesend worker.
 *
 * Отправляет неотправленные заказы на внешний сервер.
 * Запускается по cron или вручную.
 *
 * Пример:
 *   php /var/www/html/newapi/bin/timesend.php --limit=5
 *   php /var/www/html/newapi/bin/timesend.php --limit=5 --dry-run
 */

// =========================
// Lock: не запускать второй экземпляр
// =========================
$lockFile = '/tmp/smartbar-timesend.lock';

$lock = fopen($lockFile, 'c');

if ($lock === false) {
    fwrite(STDERR, "Cannot open lock file: {$lockFile}\n");
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another timesend process is already running\n";
    exit(0);
}

// =========================
// Options
// =========================
$options = getopt('', ['limit::', 'dry-run']);

$limit = isset($options['limit']) ? (int)$options['limit'] : 20;

if ($limit < 1) {
    $limit = 1;
}

if ($limit > 200) {
    $limit = 200;
}

$dryRun = array_key_exists('dry-run', $options);

// =========================
// Config
// =========================
$configFile = __DIR__ . '/../config/config.php';

if (!is_file($configFile)) {
    fwrite(STDERR, "Config file not found: {$configFile}\n");
    exit(1);
}

$config = require $configFile;

// =========================
// Log
// =========================
$logDir = __DIR__ . '/../storage/logs';

if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

$logFile = $logDir . '/timesend.log';

function logMessage(string $file, string $message): void
{
    $line = sprintf(
        "[%s] %s%s",
        date('Y-m-d H:i:s'),
        $message,
        PHP_EOL
    );

    file_put_contents($file, $line, FILE_APPEND);
}

// =========================
// HTTP client
// =========================
function postJson(string $url, string $token, array $data, int $timeout): string
{
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('Cannot init cURL');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => max(1, (int)ceil($timeout / 2)),
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
        throw new RuntimeException(
            'External API HTTP error: ' . $statusCode .
            ', body: ' . (is_string($result) ? $result : '')
        );
    }

    return is_string($result) ? $result : '';
}

// =========================
// DB connect
// =========================
try {
    $db = $config['db'];

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['name'],
        $db['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    logMessage($logFile, 'DB connect error: ' . $e->getMessage());
    fwrite(STDERR, 'DB connect error: ' . $e->getMessage() . "\n");
    exit(1);
}

// =========================
// External API settings
// =========================
$baseUrl = rtrim($config['external']['base_url'] ?? 'http://apicb.cwsys.ru/USB', '/');
$timeout = (int)($config['external']['timeout'] ?? 5);

// =========================
// Select pending orders
// =========================
try {
    $sql = "
        SELECT
            id,
            outid AS uniqid,
            date,
            time,
            uniq,
            point,
            token,
            sync_attempts
        FROM my_orders
        WHERE token IS NOT NULL
          AND token != ''
          AND sended IS NULL
          AND time_start IS NOT NULL
          AND time_start != ''
          AND time_end IS NOT NULL
          AND time_end != ''
          AND date_start IS NOT NULL
          AND date_start != ''
          AND date_end IS NOT NULL
          AND date_end != ''
          AND (
                sync_next_attempt_at IS NULL
             OR sync_next_attempt_at <= NOW()
          )
        ORDER BY id ASC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $orders = $stmt->fetchAll();
} catch (Throwable $e) {
    logMessage($logFile, 'Select pending orders error: ' . $e->getMessage());
    fwrite(STDERR, 'Select pending orders error: ' . $e->getMessage() . "\n");
    exit(1);
}

logMessage(
    $logFile,
    sprintf(
        'Run start. limit=%d, dry-run=%s, pending orders=%d',
        $limit,
        $dryRun ? 'yes' : 'no',
        count($orders)
    )
);

// =========================
// Process orders
// =========================
foreach ($orders as $order) {
    $orderId = (int)$order['id'];

    try {
        // Выбираем позиции заказа
        $itemStmt = $pdo->prepare("
            SELECT
                name,
                count,
                start_time,
                end_time,
                start_date,
                end_date,
                0 AS tech_card
            FROM my_orders_items
            WHERE `order` = ?
              AND start_time IS NOT NULL
              AND start_time != ''
              AND end_time IS NOT NULL
              AND end_time != ''
              AND start_date IS NOT NULL
              AND start_date != ''
              AND end_date IS NOT NULL
              AND end_date != ''
        ");

        $itemStmt->execute([$orderId]);

        $items = $itemStmt->fetchAll();

        if (!$items) {
            throw new RuntimeException('no completed items to send');
        }

        // Добавляем uniqid заказа в каждую позицию.
        // Это повторяет логику оригинального sendtime(),
        // где uniqid брался из outid заказа.
        foreach ($items as &$item) {
            $item = ['uniqid' => $order['uniqid']] + $item;
        }
        unset($item);

        $payload = [
            'items' => $items,
            'place' => $order['point'],
        ];

        if ($dryRun) {
            logMessage(
                $logFile,
                sprintf(
                    'DRY RUN order %d payload: %s',
                    $orderId,
                    json_encode($payload, JSON_UNESCAPED_UNICODE)
                )
            );

            continue;
        }

        $response = postJson(
            $baseUrl . '/timesend/',
            (string)$order['token'],
            $payload,
            $timeout
        );

        $decoded = json_decode($response, true);

        $rowsCount = $decoded['data'][0]['rows_count'] ?? null;

        if ($rowsCount !== null && (string)$rowsCount !== '0') {
            $updateStmt = $pdo->prepare("
                UPDATE my_orders
                SET
                    sended = 1,
                    sync_attempts = 0,
                    sync_last_error = NULL,
                    sync_next_attempt_at = NULL
                WHERE id = ?
            ");

            $updateStmt->execute([$orderId]);

            logMessage(
                $logFile,
                sprintf(
                    'Order %d sent OK, rows_count=%s',
                    $orderId,
                    (string)$rowsCount
                )
            );
        } else {
            throw new RuntimeException(
                'rows_count is empty or zero, response: ' . $response
            );
        }
    } catch (Throwable $e) {
        $attempts = (int)($order['sync_attempts'] ?? 0) + 1;

        // Экспоненциальная задержка:
        // 1, 2, 4, 8, 16, 32, 60 минут максимум
        $delayMinutes = min(60, 2 ** max(0, $attempts - 1));

        $sql = "
            UPDATE my_orders
            SET
                sync_attempts = ?,
                sync_last_error = ?,
                sync_next_attempt_at = DATE_ADD(NOW(), INTERVAL {$delayMinutes} MINUTE)
            WHERE id = ?
        ";

        $updateStmt = $pdo->prepare($sql);

        $updateStmt->execute([
            $attempts,
            $e->getMessage(),
            $orderId,
        ]);

        logMessage(
            $logFile,
            sprintf(
                'Order %d error: %s, next attempt in %d min',
                $orderId,
                $e->getMessage(),
                $delayMinutes
            )
        );
    }
}

logMessage($logFile, 'Run finished');

exit(0);