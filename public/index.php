<?php
//TEST AUTO UPDATE
declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\LegacyController;
use App\Controllers\OrderController;
use App\Controllers\TableController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

// =========================
// Обрезка префикса /newapi
// =========================
$basePath = '/newapi';

if (isset($_SERVER['REQUEST_URI'])) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

    if ($basePath !== '' && str_starts_with($path, $basePath)) {
        $newPath = substr($path, strlen($basePath)) ?: '/';
        $query = $_SERVER['QUERY_STRING'] ?? '';

        $_SERVER['REQUEST_URI'] = $newPath . ($query !== '' ? '?' . $query : '');
    }
}

// =========================
// Автозагрузчик классов
// =========================
$autoloadFile = __DIR__ . '/../vendor/autoload.php';

if (file_exists($autoloadFile)) {
    require $autoloadFile;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        $baseDir = __DIR__ . '/../app/';

        $len = strlen($prefix);

        if (strncmp($class, $prefix, $len) !== 0) {
            return;
        }

        $relativeClass = substr($class, $len);

        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require $file;
        }
    });
}

// =========================
// Errors / logging
// =========================
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/logs/php-error.log');

// =========================
// CORS
// =========================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// =========================
// Routes
// =========================
$router = new Router();

$router->get('/test', [HealthController::class, 'test']);

$router->get('/orders', [OrderController::class, 'index']);
$router->get('/orders/on-table', [OrderController::class, 'onTable']);
$router->post('/orders', [OrderController::class, 'store']);

$router->patch('/orders/{id}/close', [OrderController::class, 'close']);
$router->patch('/orders/{id}/close-on-table', [OrderController::class, 'closeOnTable']);
$router->patch('/orders/{id}/update', [OrderController::class, 'update']);
$router->patch('/orders/{id}/update-on-table', [OrderController::class, 'updateOnTable']);
$router->patch('/orders/{id}/send', [OrderController::class, 'send']);

$router->patch('/order-items/{id}/close', [OrderController::class, 'closePosition']);
$router->patch('/kuch-orders/{id}/close', [OrderController::class, 'closeKuch']);

$router->post('/tables/{table}/clear', [TableController::class, 'clear']);
$router->post('/tables/detection', [TableController::class, 'detection']);

$router->get('/json.php', [LegacyController::class, 'handle']);
$router->post('/json.php', [LegacyController::class, 'handle']);

$request = Request::fromGlobals();

try {
    $router->dispatch($request);
} catch (Throwable $e) {
    error_log($e->getMessage());

    Response::error('Internal server error', 500);
}