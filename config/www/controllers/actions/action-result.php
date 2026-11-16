<?php

declare(strict_types=1);

function linnms_action_reason(array $result, string $default = 'internal'): string
{
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    return (string)($data['reason'] ?? $result['code'] ?? $default);
}

function linnms_action_code_message(array $result, string $fallback): string
{
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    if (isset($data['reason'])) return $fallback;
    return match ((string)($result['code'] ?? 'internal')) {
        'validation' => alert_message('feature-unavailable'),
        'not_found' => alert_message('Nodata-found'),
        'forbidden' => alert_message('Access-denied'),
        'unsupported' => alert_message('feature-unavailable'),
        'device_unreachable' => alert_message('device-unreachable'),
        'device_auth_failed' => alert_message('Device-authentication-failed'),
        'device_rejected' => 'Perangkat menolak permintaan.',
        'conflict' => alert_message('Processing-data'),
        'internal' => alert_message('request-cannot'),
        default => $fallback,
    };
}

function linnms_action_result(bool $success, string $message, string $redirectUrl, string $failureLevel = 'error'): void
{
    if (strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest') {
        http_response_code($success ? 200 : 422);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash($success ? 'success' : $failureLevel, $message);
    redirect($redirectUrl);
}
