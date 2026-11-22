<?php

declare(strict_types=1);

require_once __DIR__ . '/internal-api-client.php';

$logMessageHelper = dirname(realpath(__DIR__) ?: __DIR__, 2) . '/app/runtime/src/helpers/log-message.php';
require_once is_file($logMessageHelper) ? $logMessageHelper : '/opt/linnms/runtime/src/helpers/log-message.php';
unset($logMessageHelper);

function application_log_event(?int $userId, string $area, string $action, string $level, string $message, array $context = [], ?string $actor = null, ?string $ipAddress = null): void
{
    static $writing = false;
    if ($writing) return;
    $writing = true;
    try {
        $result = internal_api_request(max(0, (int)$userId), 'app.log.write', [
            'area' => $area,
            'action' => $action,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'actor' => $actor,
            'ip_address' => $ipAddress ?? (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ], 3);
        if (($result['written'] ?? false) !== true) throw new RuntimeException('Penyimpanan log tidak dikonfirmasi.');
    } catch (Throwable $error) {
        error_log('[LINNMS] [CRITICAL] Log API: ' . $error->getMessage());
        internal_api_critical_failure(linnms_log_message('error', 'app.database-unavailable'));
    } finally {
        $writing = false;
    }
}

function application_log_error(Throwable $error, string $action = 'application_error', string $level = 'error'): void
{
    static $fingerprints = [];
    $fingerprint = get_class($error) . '|' . $error->getMessage() . '|' . $error->getFile() . '|' . $error->getLine();
    if (isset($fingerprints[$fingerprint])) return;
    $fingerprints[$fingerprint] = true;
    application_log_event(current_user_id() ?: null, 'LINNMS APP', $action, $level, (string)$error,
        [], current_username() ?: null);
    if ($level === 'error') $GLOBALS['LINNMS_ORIGINAL_ERROR_LOGGED'] = true;
}

function register_application_error_logging(): void
{
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ((error_reporting() & $severity) === 0) return false;
        $level = match ($severity) {
            E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_STRICT => 'info',
            E_WARNING, E_USER_WARNING => 'warning',
            default => 'error',
        };
        application_log_error(new ErrorException($message, 0, $severity, $file, $line), 'php_error', $level);
        return false;
    });
    register_shutdown_function(static function (): void {
        $fatal = error_get_last();
        if (!is_array($fatal) || !in_array((int)($fatal['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $status = http_response_code();
            if ($status >= 500 && empty($GLOBALS['LINNMS_ORIGINAL_ERROR_LOGGED'])) {
                application_log_event(current_user_id() ?: null, 'System', 'application_error', 'error',
                    linnms_log_message('error', 'app.http-error', ['status' => $status]), ['http_status' => $status], current_username() ?: null);
            }
            return;
        }
        application_log_error(new ErrorException(
            (string)($fatal['message'] ?? linnms_log_message('error', 'app.unknown-fatal')), 0, (int)$fatal['type'],
            (string)($fatal['file'] ?? ''), (int)($fatal['line'] ?? 0)
        ), 'php_fatal_error');
    });
}
