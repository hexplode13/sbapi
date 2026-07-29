<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

class LegacyController extends Controller
{
    public function handle(Request $request): void
    {
        $command = (string)$request->input('command', 'getorders');

        if ($request->has('addorderinbase')) {
            $command = 'addorder';
        }

        if ($command === '_construct') {
            Response::error('Access Denied!', 403);
        }

        $orderController = new OrderController();
        $tableController = new TableController();
        $healthController = new HealthController();

        switch ($command) {
            case 'getorders':
                $orderController->index($request);
                break;

            case 'getordersontable':
                $orderController->onTable($request);
                break;

            case 'addorder':
                $orderController->store($request);
                break;

            case 'closeorder':
                $this->callWithOrder($orderController, 'close', $request);
                break;

            case 'closeorderontable':
                $this->callWithOrder($orderController, 'closeOnTable', $request);
                break;

            case 'updateorder':
                $this->callWithOrder($orderController, 'update', $request);
                break;

            case 'updateorderontable':
                $this->callWithOrder($orderController, 'updateOnTable', $request);
                break;

            case 'sendorder':
                $this->callWithOrder($orderController, 'send', $request);
                break;

            case 'closeposition':
                $this->callWithParam($orderController, 'closePosition', $request, 'position');
                break;

            case 'closekuchorder':
                $this->callWithOrder($orderController, 'closeKuch', $request);
                break;

            case 'cleartable':
                $this->callWithParam($tableController, 'clear', $request, 'table');
                break;

            case 'detection':
                $tableController->detection($request);
                break;

            case 'test':
                $healthController->test($request);
                break;

            default:
                Response::error("Method doesn't exists!", 400);
        }
    }

    private function callWithOrder(object $controller, string $method, Request $request): void
    {
        $orderId = (int)$request->input('order', 0);

        $controller->$method($request, [
            'id' => $orderId,
        ]);
    }

    private function callWithParam(object $controller, string $method, Request $request, string $param): void
    {
        $value = (int)$request->input($param, 0);

        $controller->$method($request, [
            'id' => $value,
        ]);
    }
}