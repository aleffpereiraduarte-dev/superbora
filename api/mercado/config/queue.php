<?php
/**
 * JobQueue - Redis-based async job queue
 *
 * Producers: API endpoints push jobs (notifications, emails, webhooks)
 * Consumer:  Worker daemon processes jobs from queue
 *
 * Redis DB 1 used for queue data
 */

require_once __DIR__ . '/redis.php';

class JobQueue {
    private static ?self $instance = null;
    private ?Redis $redis = null;
    private bool $available = false;

    private const QUEUE_KEY = 'queue:jobs';
    private const FAILED_KEY = 'queue:failed';
    private const PROCESSING_KEY = 'queue:processing';
    private const STATS_KEY = 'queue:stats';

    private function __construct() {
        $svc = RedisService::getInstance();
        if ($svc->isAvailable()) {
            $this->redis = $svc->getRawConnection(1);
            $this->available = $this->redis !== null;
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function isAvailable(): bool {
        return $this->available;
    }

    /**
     * Push a job onto the queue
     */
    public function push(string $type, array $payload, int $priority = 0): ?string {
        if (!$this->available) return null;

        $jobId = bin2hex(random_bytes(8));
        $job = [
            'id' => $jobId,
            'type' => $type,
            'payload' => $payload,
            'priority' => $priority,
            'created_at' => date('c'),
            'attempts' => 0,
        ];

        try {
            $this->redis->lPush(self::QUEUE_KEY, json_encode($job));
            $this->redis->hIncrBy(self::STATS_KEY, 'total_pushed', 1);
            return $jobId;
        } catch (\RedisException $e) {
            error_log("[Queue] Push failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Pop next job from queue (blocking with timeout)
     */
    public function pop(int $timeout = 5): ?array {
        if (!$this->available) return null;

        try {
            $result = $this->redis->brPop(self::QUEUE_KEY, $timeout);
            if (!$result) return null;

            $job = json_decode($result[1], true);
            if (!$job) return null;

            $job['attempts']++;
            $job['started_at'] = date('c');

            // Move to processing set
            $this->redis->hSet(self::PROCESSING_KEY, $job['id'], json_encode($job));

            return $job;
        } catch (\RedisException $e) {
            error_log("[Queue] Pop failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark job as completed
     */
    public function complete(string $jobId): void {
        if (!$this->available) return;
        try {
            $this->redis->hDel(self::PROCESSING_KEY, $jobId);
            $this->redis->hIncrBy(self::STATS_KEY, 'total_completed', 1);
        } catch (\RedisException $e) {
            error_log("[Queue] Complete failed: " . $e->getMessage());
        }
    }

    /**
     * Mark job as failed (retry or move to failed queue)
     */
    public function fail(string $jobId, string $error, int $maxRetries = 3): void {
        if (!$this->available) return;
        try {
            $jobData = $this->redis->hGet(self::PROCESSING_KEY, $jobId);
            $this->redis->hDel(self::PROCESSING_KEY, $jobId);

            if ($jobData) {
                $job = json_decode($jobData, true);
                $job['last_error'] = $error;
                $job['failed_at'] = date('c');

                if ($job['attempts'] < $maxRetries) {
                    // Re-queue for retry
                    $this->redis->lPush(self::QUEUE_KEY, json_encode($job));
                    $this->redis->hIncrBy(self::STATS_KEY, 'total_retried', 1);
                } else {
                    // Move to failed queue
                    $this->redis->lPush(self::FAILED_KEY, json_encode($job));
                    $this->redis->hIncrBy(self::STATS_KEY, 'total_failed', 1);
                }
            }
        } catch (\RedisException $e) {
            error_log("[Queue] Fail failed: " . $e->getMessage());
        }
    }

    /**
     * Get queue statistics
     */
    public function stats(): array {
        if (!$this->available) return ['available' => false];
        try {
            return [
                'available' => true,
                'pending' => $this->redis->lLen(self::QUEUE_KEY),
                'processing' => $this->redis->hLen(self::PROCESSING_KEY),
                'failed' => $this->redis->lLen(self::FAILED_KEY),
                'total_pushed' => (int)$this->redis->hGet(self::STATS_KEY, 'total_pushed'),
                'total_completed' => (int)$this->redis->hGet(self::STATS_KEY, 'total_completed'),
                'total_failed' => (int)$this->redis->hGet(self::STATS_KEY, 'total_failed'),
            ];
        } catch (\RedisException $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Queue size
     */
    public function size(): int {
        if (!$this->available) return 0;
        try {
            return $this->redis->lLen(self::QUEUE_KEY);
        } catch (\RedisException $e) {
            return 0;
        }
    }
}

// ── Convenience functions for common job types ──

/**
 * Queue a push notification job
 */
function queuePushNotification(int $userId, string $userType, string $title, string $body, array $data = []): bool {
    $queue = JobQueue::getInstance();
    if (!$queue->isAvailable()) return false;

    $jobId = $queue->push('push_notification', [
        'user_id' => $userId,
        'user_type' => $userType,
        'title' => $title,
        'body' => $body,
        'data' => $data,
    ]);

    return $jobId !== null;
}

/**
 * Queue a WhatsApp message job
 */
function queueWhatsApp(string $phone, string $type, array $params = []): bool {
    $queue = JobQueue::getInstance();
    if (!$queue->isAvailable()) return false;

    $jobId = $queue->push('whatsapp', [
        'phone' => $phone,
        'type' => $type,
        'params' => $params,
    ]);

    return $jobId !== null;
}

/**
 * Queue an email job
 */
function queueEmail(string $to, string $subject, string $template, array $data = []): bool {
    $queue = JobQueue::getInstance();
    if (!$queue->isAvailable()) return false;

    $jobId = $queue->push('email', [
        'to' => $to,
        'subject' => $subject,
        'template' => $template,
        'data' => $data,
    ]);

    return $jobId !== null;
}

/**
 * Helper global
 */
function om_queue(): JobQueue {
    return JobQueue::getInstance();
}
