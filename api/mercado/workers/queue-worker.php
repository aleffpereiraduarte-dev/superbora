<?php
/**
 * Queue Worker - Processes jobs from Redis queue
 *
 * Run: php /var/www/html/api/mercado/workers/queue-worker.php
 * Daemon: systemctl start superbora-worker
 *
 * Handles: push_notification, whatsapp, email
 */

// Don't timeout
set_time_limit(0);
ini_set('memory_limit', '128M');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/queue.php';

$workerPid = getmypid();
$startTime = time();
$jobsProcessed = 0;
$maxJobs = 1000; // Restart after 1000 jobs (prevent memory leaks)
$maxRuntime = 3600; // Restart after 1 hour

echo "[Worker $workerPid] Started at " . date('Y-m-d H:i:s') . "\n";

// Graceful shutdown
$running = true;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function() use (&$running, $workerPid) {
    echo "[Worker $workerPid] SIGTERM received, shutting down...\n";
    $running = false;
});
pcntl_signal(SIGINT, function() use (&$running, $workerPid) {
    echo "[Worker $workerPid] SIGINT received, shutting down...\n";
    $running = false;
});

$queue = JobQueue::getInstance();
if (!$queue->isAvailable()) {
    echo "[Worker $workerPid] ERROR: Redis not available!\n";
    exit(1);
}

$db = null; // Lazy load DB connection

while ($running) {
    // Check limits
    if ($jobsProcessed >= $maxJobs) {
        echo "[Worker $workerPid] Max jobs ($maxJobs) reached, restarting...\n";
        break;
    }
    if ((time() - $startTime) >= $maxRuntime) {
        echo "[Worker $workerPid] Max runtime reached, restarting...\n";
        break;
    }

    // Pop next job (blocks for 5 seconds)
    $job = $queue->pop(5);
    if (!$job) continue;

    $jobId = $job['id'];
    $jobType = $job['type'];

    echo "[Worker $workerPid] Processing job $jobId ($jobType) attempt #{$job['attempts']}...\n";

    try {
        // Lazy load DB
        if ($db === null) {
            $db = getDB();
        }

        switch ($jobType) {
            case 'push_notification':
                processNotification($db, $job['payload']);
                break;

            case 'whatsapp':
                processWhatsApp($job['payload']);
                break;

            case 'email':
                processEmail($job['payload']);
                break;

            case 'in_app_notification':
                processInAppNotification($db, $job['payload']);
                break;

            default:
                echo "[Worker $workerPid] Unknown job type: $jobType\n";
                $queue->fail($jobId, "Unknown job type: $jobType", 0);
                continue 2;
        }

        $queue->complete($jobId);
        $jobsProcessed++;
        echo "[Worker $workerPid] Job $jobId completed\n";

    } catch (Exception $e) {
        echo "[Worker $workerPid] Job $jobId failed: " . $e->getMessage() . "\n";
        error_log("[Queue Worker] Job $jobId ($jobType) failed: " . $e->getMessage());
        $queue->fail($jobId, $e->getMessage());
    }
}

echo "[Worker $workerPid] Stopped. Processed $jobsProcessed jobs.\n";
exit(0);

// ── Job Processors ──

function processNotification(PDO $db, array $payload): void {
    $userId = (int)$payload['user_id'];
    $userType = $payload['user_type'];
    $title = $payload['title'];
    $body = $payload['body'];
    $data = $payload['data'] ?? [];

    // FCM Push via NotificationSender
    require_once __DIR__ . '/../helpers/NotificationSender.php';
    $sender = NotificationSender::getInstance($db);

    if ($userType === 'customer') {
        $sender->notifyCustomer($userId, $title, $body, $data);
    } elseif ($userType === 'partner') {
        $sender->notifyPartner($userId, $title, $body, $data);
    } elseif ($userType === 'shopper') {
        $sender->notifyShopper($userId, $title, $body, $data);
    } else {
        throw new Exception("Invalid user_type for push_notification: '$userType'. Expected customer, partner, or shopper.");
    }
}

function processInAppNotification(PDO $db, array $payload): void {
    require_once __DIR__ . '/../config/notify.php';
    sendNotification(
        $db,
        (int)$payload['user_id'],
        $payload['user_type'],
        $payload['title'],
        $payload['body'],
        $payload['data'] ?? []
    );
}

function processWhatsApp(array $payload): void {
    require_once __DIR__ . '/../helpers/zapi-whatsapp.php';

    $phone = $payload['phone'];
    $type = $payload['type'];
    $params = $payload['params'] ?? [];

    switch ($type) {
        case 'order_accepted':
            whatsappOrderAccepted($phone, $params['order_number'] ?? '');
            break;
        case 'order_preparing':
            whatsappOrderPreparing($phone, $params['order_number'] ?? '');
            break;
        case 'order_ready':
            whatsappOrderReady($phone, $params['order_number'] ?? '');
            break;
        case 'order_cancelled':
            whatsappOrderCancelled($phone, $params['order_number'] ?? '');
            break;
        default:
            throw new Exception("Unknown WhatsApp type: $type");
    }
}

function processEmail(array $payload): void {
    require_once __DIR__ . '/../helpers/EmailService.php';

    $emailService = EmailService::getInstance();
    $to = $payload['to'];
    $subject = $payload['subject'];
    $template = $payload['template'];
    $data = $payload['data'] ?? [];

    switch ($template) {
        case 'order_confirmation':
            $emailService->sendOrderConfirmation($to, $data);
            break;
        case 'delivery_update':
            $emailService->sendDeliveryUpdate($to, $data);
            break;
        case 'welcome':
            $emailService->sendWelcome($to, $data);
            break;
        default:
            throw new Exception("Unknown email template: $template");
    }
}
