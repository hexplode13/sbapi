<?php

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'checks_db',
        'user' => 'smartbar',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    'cors' => [
        'allow_origin' => '*',
    ],

    'external' => [
        'base_url' => 'http://apicb.cwsys.ru/USB',
        'timeout' => 5,
        'connect_timeout' => 2,
    ],

    'sync' => [
        'batch_size' => 20,
        'sleep_seconds' => 5,
        'max_attempts' => 5,
    ],
];