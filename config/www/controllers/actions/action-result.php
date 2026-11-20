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
        'validation' => toast_message('validation.required-fields'),
        'not_found' => toast_message('request.not-found'),
        'forbidden' => toast_message('access.denied'),
        'unsupported' => toast_message('feature.unavailable'),
        'device_unreachable' => toast_message('device.unreachable'),
        'device_auth_failed' => toast_message('device.auth-failed'),
        'device_rejected' => 'Perangkat menolak permintaan.',
        'conflict' => toast_message('request.processing'),
        'internal' => toast_message('request.unavailable'),
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
