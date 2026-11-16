<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/action-result.php';

function connection_test_result(int $userId, array $input): array
{
    $type = is_scalar($input['type'] ?? null) ? trim((string)$input['type']) : '';
    return match ($type) {
        'device' => connection_test_device_result($userId, $input),
        'telegram' => connection_test_telegram_result($userId, $input),
        'whatsapp' => connection_test_whatsapp_result($userId, $input),
        default => ['status' => 422, 'payload' => ['ok' => false, 'message' => alert_message('connection-test-jenis-tes-koneksi-tidak-dikenal')]],
    };
}

function connection_test_device_result(int $userId, array $input): array
{
    $params = [];
    foreach ([
        'driver_id',
        'device_row_id',
        'ip_address',
        'snmp_port',
        'community',
        'ports',
        'username',
        'password',
        'hybrid_status',
    ] as $field) {
        if (!array_key_exists($field, $input) || !is_scalar($input[$field])) continue;
        $params[$field] = (string)$input[$field];
    }

    try {
        $result = internal_api_request($userId, 'connection_test', ['type' => 'device', 'data' => $params], 35);
    } catch (Throwable $error) {
        error_log('[LINNMS] Tes koneksi web: ' . $error->getMessage());
        return [
            'status' => 502,
            'payload' => ['ok' => false, 'message' => alert_message('connection-test-tes-koneksi-tidak-dapat-dijalankan')],
        ];
    }

    $ok = !empty($result['ok']);
    $code = trim((string)($result['code'] ?? ''));
    $message = match ($code) {
        'ok' => alert_message('connection-test-koneksi-perangkat-berhasil'),
        'validation' => alert_message('connection-test-periksa-kembali-data-perangkat-yang-diisi'),
        'not_found' => alert_message('live-perangkat-tidak-ditemukan'),
        'forbidden' => alert_message('live-akses-perangkat-tidak-diizinkan'),
        'unsupported' => alert_message('connection-test-tes-koneksi-belum-tersedia-untuk-perangkat-ini'),
        'device_unreachable' => alert_message('connection-test-perangkat-tidak-dapat-dijangkau-periksa-alamat-port-dan-kredensialnya'),
        'device_auth_failed' => alert_message('connection-test-autentikasi-perangkat-gagal-periksa-kredensialnya'),
        'device_rejected' => alert_message('connection-test-perangkat-menolak-tes-koneksi'),
        'conflict' => alert_message('connection-test-perangkat-sedang-diproses-coba-lagi'),
        'internal' => alert_message('connection-test-tes-koneksi-tidak-dapat-diproses'),
        default => $ok ? alert_message('connection-test-koneksi-perangkat-berhasil') : alert_message('connection-test-tes-koneksi-gagal'),
    };
    $status = $ok ? 200 : match ($code) {
        'validation', 'unsupported' => 422,
        'not_found' => 404,
        'forbidden' => 403,
        'conflict' => 409,
        'device_unreachable', 'device_auth_failed', 'device_rejected' => 502,
        default => 500,
    };

    return [
        'status' => $status,
        'payload' => ['ok' => $ok, 'message' => $message],
    ];
}

function connection_test_request(int $userId, string $type, array $data): array
{
    try {
        return internal_api_request($userId, 'connection_test', ['type' => $type, 'data' => $data], 45);
    } catch (Throwable $error) {
        error_log('[LINNMS] Tes koneksi ' . $type . ': ' . $error->getMessage());
        return ['ok' => false, 'code' => 'internal'];
    }
}

function connection_test_reason(array $result): string
{
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    return (string)($data['reason'] ?? $result['code'] ?? 'internal');
}

function connection_test_telegram_result(int $userId, array $input): array
{
    $chat = trim((string)(is_scalar($input['telegram_chat_id'] ?? null) ? $input['telegram_chat_id'] : ''));
    $token = trim((string)(is_scalar($input['telegram_bot_token'] ?? null) ? $input['telegram_bot_token'] : ''));
    $result = $chat !== '' && preg_match('/^-?\d{1,24}$/', $chat) !== 1
        ? ['ok' => false, 'code' => 'chat_invalid']
        : connection_test_request($userId, 'telegram', [
            'telegram_bot_token' => $token,
            'telegram_chat_id' => $chat,
        ]);
    $code = connection_test_reason($result);
    $message = match ($code) {
        'ok' => alert_message('connection-test-pesan-berhasil-terkirim'),
        'unsupported' => alert_message('telegram-telegram-belum-tersedia'),
        'token_required' => alert_message('telegram-token-bot-wajib-diisi'),
        'chat_required' => alert_message('connection-test-chat-id-wajib-diisi'),
        'chat_invalid' => alert_message('telegram-chat-id-harus-berupa-angka'),
        'device_rejected' => alert_message('connection-test-pesan-gagal-dikirim'),
        default => linnms_action_code_message($result, alert_message('connection-test-tes-koneksi-gagal-2')),
    };
    $status = match ($code) {
        'ok' => 200,
        'unsupported' => 403,
        'token_required', 'chat_required', 'chat_invalid' => 422,
        'device_rejected' => 502,
        default => 500,
    };
    return ['status' => $status, 'payload' => ['ok' => !empty($result['ok']), 'message' => $message]];
}

function connection_test_whatsapp_result(int $userId, array $input): array
{
    $data = [];
    foreach (['whatsapp_provider', 'whatsapp_target_type', 'whatsapp_token', 'whatsapp_device',
        'whatsapp_base_url', 'whatsapp_target', 'whatsapp_generator_session',
        'whatsapp_generator_secret'] as $key) {
        if (array_key_exists($key, $input) && is_scalar($input[$key])) {
            $data[$key] = trim((string)$input[$key]);
        }
    }
    $result = connection_test_request($userId, 'whatsapp', $data);
    $code = connection_test_reason($result);
    $message = match ($code) {
        'ok' => alert_message('connection-test-pesan-berhasil-terkirim'),
        'unsupported' => alert_message('connection-test-whatsapp-belum-tersedia'),
        'missing_fields' => alert_message('whatsapp-lengkapi-kolom-wajib'),
        'group_invalid' => alert_message('connection-test-group-id-whatsapp-tidak-valid'),
        'target_invalid' => alert_message('connection-test-nomor-tujuan-tidak-valid'),
        'device_rejected' => alert_message('connection-test-pesan-gagal-dikirim'),
        default => linnms_action_code_message($result, 'Tes WhatsApp mengalami kesalahan internal.'),
    };
    $status = match ($code) {
        'ok' => 200,
        'unsupported' => 403,
        'missing_fields', 'group_invalid', 'target_invalid' => 422,
        'device_rejected' => 502,
        default => 500,
    };
    return ['status' => $status, 'payload' => ['ok' => !empty($result['ok']), 'message' => $message]];
}
