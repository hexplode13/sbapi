<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;

abstract class Controller
{
    protected function success(mixed $data = null, int $status = 200): never
    {
        Response::success($data, $status);
    }

    protected function error(mixed $data = 'Error', int $status = 400): never
    {
        Response::error($data, $status);
    }
}