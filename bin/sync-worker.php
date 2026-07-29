<?php

declare(strict_types=1);

use App\Workers\SyncWorker;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';

$batchSize = (int)($config['sync']['batch_size'] ?? 20);
$sleepSeconds = (int)($config['sync']['sleep_seconds'] ?? 5);

$worker = new SyncWorker($batchSize, $sleepSeconds);

$mode = $argv[1] ?? 'loop';

if ($mode === '--once') {
    $worker->runOnce();
    exit(0);
}

$worker->loop();