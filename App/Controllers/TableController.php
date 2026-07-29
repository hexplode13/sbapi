<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\TableService;
use Throwable;

class TableController extends Controller
{
    private TableService $tables;

    public function __construct()
    {
        $this->tables = new TableService();
    }

    public function clear(Request $request, array $params): void
    {
        $table = (int)$params['table'];

        try {
            $result = $this->tables->clearTable($table);

            $this->success($result);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->error($e->getMessage());
        }
    }

    public function detection(Request $request): void
    {
        $table = (int)$request->input('table', 0);
        $active = (int)$request->input('active', 0);

        try {
            $this->tables->setActive($table, $active);

            $this->success([
                'table' => $table,
                'active' => $active,
            ]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->error($e->getMessage());
        }
    }
}