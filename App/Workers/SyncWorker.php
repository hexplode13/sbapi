<?php

declare(strict_types=1);

namespace App\Workers;

use App\Core\Database;
use App\Services\ExternalApiService;
use PDO;
use Throwable;

class SyncWorker
{
    private PDO $db;
    private ExternalApiService $externalApi;
    private int $batchSize;
    private int $sleepSeconds;

    public function __construct(int $batchSize = 20, int $sleepSeconds = 5)
    {
        $this->db = Database::pdo();
        $this->externalApi = new ExternalApiService();
        $this->batchSize = $batchSize;
        $this->sleepSeconds = $sleepSeconds;
    }

    public function loop(): void
    {
        while (true) {
            $processed = $this->runOnce();

            if ($processed === 0) {
                sleep($this->sleepSeconds);
            }
        }
    }

    public function runOnce(): int
    {
        $jobs = $this->reserveJobs();

        foreach ($jobs as $job) {
            $this->processJob($job);
        }

        return count($jobs);
    }

    private function reserveJobs(): array
    {
        $this->db->beginTransaction();

        try {
            // Для MySQL 8+
            $stmt = $this->db->prepare("
                SELECT *
                FROM sync_jobs
                WHERE status = 'pending'
                  AND available_at <= NOW()
                ORDER BY id ASC
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            ");

            $stmt->bindValue(':limit', $this->batchSize, PDO::PARAM_INT);
            $stmt->execute();

            $jobs = $stmt->fetchAll();

            if (!$jobs) {
                $this->db->commit();

                return [];
            }

            $ids = array_map(
                static fn(array $job): int => (int)$job['id'],
                $jobs
            );

            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $stmt = $this->db->prepare("
                UPDATE sync_jobs
                SET
                    status = 'processing',
                    reserved_at = NOW(),
                    updated_at = NOW()
                WHERE id IN ($placeholders)
            ");

            $stmt->execute($ids);

            $this->db->commit();

            return $jobs;
        } catch (Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    private function processJob(array $job): void
    {
        try {
            $payload = json_decode((string)($job['payload'] ?? '{}'), true) ?: [];

            switch ($job['type']) {
                case 'order.timesend':
                    $orderId = (int)($payload['order_id'] ?? 0);

                    if ($orderId > 0) {
                        $this->externalApi->sendOrderTimesend($orderId);
                    }

                    break;

                default:
                    throw new RuntimeException('Unknown job type: ' . $job['type']);
            }

            $this->markDone((int)$job['id']);
        } catch (Throwable $e) {
            error_log('Sync job error: ' . $e->getMessage());

            $this->markFailed((int)$job['id'], (int)$job['attempts'], $e->getMessage());
        }
    }

    private function markDone(int $jobId): void
    {
        $stmt = $this->db->prepare("
            UPDATE sync_jobs
            SET
                status = 'done',
                last_error = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([$jobId]);
    }

    private function markFailed(int $jobId, int $attempts, string $error): void
    {
        $attempts++;

        // Exponential backoff: 1, 2, 4, 8, 16 минут
        $delayMinutes = min(60, 2 ** max(0, $attempts - 1));

        $stmt = $this->db->prepare("
            UPDATE sync_jobs
            SET
                status = IF(attempts >= max_attempts, 'failed', 'pending'),
                attempts = :attempts,
                last_error = :error,
                available_at = DATE_ADD(NOW(), INTERVAL :delay MINUTE),
                reserved_at = NULL,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'attempts' => $attempts,
            'error' => $error,
            'delay' => $delayMinutes,
            'id' => $jobId,
        ]);
    }
}