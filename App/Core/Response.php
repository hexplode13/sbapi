<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($data, JSON_UNESCAPED_UNICODE);

        exit;
    }

    public static function success(mixed $data = null, int $status = 200): never
    {
        self::json([
            'answer' => 'success',
            'data' => $data,
        ], $status);
    }

    public static function error(mixed $data = 'Error', int $status = 400): never
    {
        self::json([
            'answer' => 'error',
            'data' => $data,
        ], $status);
    }
}