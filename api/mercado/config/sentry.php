<?php
/**
 * Sentry Error Monitoring
 *
 * Captures exceptions, errors, and performance data.
 * DSN configured via environment variable SENTRY_DSN.
 *
 * Usage:
 *   require_once 'config/sentry.php';
 *   // Exceptions auto-captured. Manual:
 *   \Sentry\captureException($e);
 *   \Sentry\captureMessage("Something happened");
 */

// Only init if Sentry SDK is available
if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
}

if (!class_exists('\Sentry\SentrySdk')) {
    // Sentry not installed — provide no-op functions
    return;
}

$sentryDsn = $_ENV['SENTRY_DSN'] ?? getenv('SENTRY_DSN') ?: '';

if (empty($sentryDsn)) {
    // No DSN configured — Sentry disabled silently
    return;
}

\Sentry\init([
    'dsn' => $sentryDsn,
    'environment' => $_ENV['APP_ENV'] ?? 'production',
    'release' => 'superbora-api@1.0.0',
    'traces_sample_rate' => 0.1, // 10% of requests for performance monitoring
    'profiles_sample_rate' => 0.1,
    'send_default_pii' => false, // Don't send personal info by default

    // Filter noisy errors: sample rate-limiting and auth failures at 1% instead of dropping entirely
    // SECURITY: Fully suppressing these would hide brute-force attack signals
    'before_send' => function (\Sentry\Event $event, ?\Sentry\EventHint $hint): ?\Sentry\Event {
        $exception = $hint?->exception;
        if ($exception) {
            $msg = $exception->getMessage();
            // Sample rate-limiting and auth failures at 1% to detect sustained attacks
            if (str_contains($msg, 'Muitas requisicoes') || str_contains($msg, 'Token ausente')) {
                if (random_int(1, 100) > 1) {
                    return null; // Drop 99% of these
                }
                // Let 1% through so sustained attacks generate alerts
            }
        }
        return $event;
    },
]);

// Set global tags
\Sentry\configureScope(function (\Sentry\State\Scope $scope): void {
    $scope->setTag('service', 'superbora-api');
    $scope->setTag('php_version', PHP_VERSION);
});

// Register shutdown handler to capture fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        \Sentry\captureLastError();
    }
});

/**
 * Helper: capture exception with context
 */
function sentryCapture(Throwable $e, array $context = []): void {
    if (!class_exists('\Sentry\SentrySdk')) return;

    \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($e, $context): void {
        foreach ($context as $key => $value) {
            $scope->setExtra($key, $value);
        }
        \Sentry\captureException($e);
    });
}

/**
 * Helper: set user context for current request
 */
function sentrySetUser(int $userId, string $userType): void {
    if (!class_exists('\Sentry\SentrySdk')) return;

    \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($userId, $userType): void {
        $scope->setUser([
            'id' => $userId,
            'segment' => $userType,
        ]);
    });
}
