<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\OrderService;
use Throwable;

class OrderController extends Controller
{
    private OrderService $orders;

    public function __construct()
    {
        $this->orders = new OrderService();
    }

    public function index(Request $request): void
    {
        $kuch = $request->has('kuch');
        $date = $request->query('date');

        $orders = $this->orders->getOrders(
            kuch: $kuch,
            date: is_string($date) ? $date : null,
            onlyTables: false
        );

        $this->success($orders);
    }

    public function onTable(Request $request): void
    {
        $kuch = $request->has('kuch');
        $date = $request->query('date');

        $orders = $this->orders->getOrders(
            kuch: $kuch,
            date: is_string($date) ? $date : null,
            onlyTables: true
        );

        $this->success($orders);
    }

    public function store(Request $request): void
    {
        $data = $request->input('data');

        if (is_string($data)) {
            $decoded = json_decode($data, true);

            if (!is_array($decoded)) {
                $this->error('Bad JSON data');
            }

            $data = $decoded;
        }

        if (!is_array($data)) {
            $data = $request->json;
        }

        try {
            $result = $this->orders->create($data);

            $this->success([
                'order' => (string)($result['outid'] ?? $result['id']),
            ]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->error('Not inserted!');
        }
    }

    public function close(Request $request, array $params): void
    {
        $orderId = (int)$params['id'];

        try {
            $this->orders->close($orderId, false);

            $this->success([
                'order' => $orderId,
            ]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->error($e->getMessage());
        }
    }

    public function closeOnTable(Request $request, array $params): void
    {
        $orderId = (int)$params['id'];

        try {
            $this->orders->close($orderId, true);

            $this->success([
                'order' => $orderId,
            ]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->error($e->getMessage());
        }
    }

    public function update(Request $request, array $params): void
    {
        $orderId = (int)$params['id'];

        try {
            $this->orders->update($orderId, false);

            $this->success([
                'order' => $orderId,
            ]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->error($e->getMessage());
        }
    }

    public function updateOnTable(Request $request, array $params): void
    {
        $orderId = (int)$params['id'];

        try {
            $result = $this->orders->update($orderId, true);

            $this->success($result);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->error($e->getMessage());
        }
    }

    public function send(Request $request, array $params): void
    {
        $orderId = (int)$params['id'];

        try {
            $this->orders->send($orderId);

            $this->success([
                'order' => $orderId,
            ]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->error($e->getMessage());
        }
    }

    public function closePosition(Request $request, array $params): void
    {
        $positionId = (int)$params['id'];

        try {
            $this->orders->closePosition($positionId);

            $this->success([
                'position' => $positionId,
            ]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->error($e->getMessage());
        }
    }

    public function closeKuch(Request $request, array $params): void
    {
        $orderId = (int)$params['id'];

        try {
            $this->orders->closeKuch($orderId);

            $this->success([
                'order' => $orderId,
            ]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->error($e->getMessage());
        }
    }
}