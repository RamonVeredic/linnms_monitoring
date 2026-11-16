<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function live_error_result(string $code): array
{
    $status = match ($code) {
        'unauthorized' => 401,
        'forbidden' => 403,
        'method_not_allowed' => 405,
        'conflict' => 409,
        default => 404,
    };
    $message = match ($code) {
        'unauthorized' => alert_message('data-sesi-aplikasi-telah-berakhir'),
        'forbidden' => alert_message('live-akun-tidak-aktif'),
        'method_not_allowed' => alert_message('data-method-tidak-didukung'),
        'conflict' => alert_message('live-konteks-realtime-berakhir-muat-ulang-halaman-perangkat'),
        default => alert_message('data-permintaan-tidak-didukung'),
    };
    return ['status' => $status, 'payload' => ['ok' => false, 'message' => $message]];
}

function live_direct_result(int $userId, string $type, array $context, array $query): array
{
    $indexes = array_slice(array_values(array_unique(array_filter(array_map('intval',
        explode(',', (string)($query['indexes'] ?? ''))), static fn(int $value): bool => $value > 0))), 0, 256);
    try {
        $result = internal_api_request($userId, 'device.realtime.read', [
            'type' => $type,
            'context' => $context,
            'interface' => trim((string)($query['interface'] ?? '')),
            'indexes' => $indexes,
        ], 30);
    } catch (Throwable $error) {
        error_log('[LINNMS] Device realtime API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal', 'data' => []];
    }
    $ok = !empty($result['ok']);
    $code = (string)($result['code'] ?? 'internal');
    if ($ok) return ['status' => 200, 'payload' => ['ok' => true]
        + (is_array($result['data'] ?? null) ? $result['data'] : [])];
    return ['status' => $code === 'conflict' ? 409 : ($code === 'forbidden' ? 403 : 502), 'payload' => [
        'ok' => false,
        'message' => $code === 'forbidden' ? alert_message('live-akun-tidak-aktif') : ($code === 'conflict'
            ? alert_message('live-konteks-realtime-berakhir-muat-ulang-halaman-perangkat')
            : ($type === 'device-traffic' ? alert_message('live-data-trafik-perangkat-tidak-dapat-dibaca') : alert_message('live-data-realtime-perangkat-tidak-dapat-dibaca'))),
    ]];
}

function live_device_action_result(int $userId, string $type, array $input): array
{
    unset($input['csrf']);
    try {
        $result = internal_api_request($userId, 'live.device.action', ['type' => $type, 'data' => $input], 480);
    } catch (Throwable $error) {
        error_log('[LINNMS] Live device action API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal', 'data' => []];
    }
    $ok = !empty($result['ok']);
    $code = (string)($result['code'] ?? 'internal');
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $messages = [
        'olt-uplink-save' => [alert_message('live-konfigurasi-uplink-tersimpan'), alert_message('live-konfigurasi-uplink-gagal-disimpan')],
        'olt-pon-flow-control-save' => [alert_message('live-flow-control-pon-tersimpan'), alert_message('live-flow-control-pon-gagal-disimpan')],
        'olt-profile-save' => [alert_message('live-profil-tersimpan'), alert_message('olt-profil-gagal-disimpan')],
        'olt-profile-delete' => [alert_message('live-profil-dihapus'), alert_message('olt-profil-gagal-dihapus')],
        'onu-interface-state-save' => [alert_message('live-status-antarmuka-tersimpan'), alert_message('live-status-antarmuka-gagal-disimpan')],
        'onu-service-config-delete' => [alert_message('live-layanan-onu-dihapus'), alert_message('live-layanan-onu-gagal-dihapus')],
        'onu-tr069-save' => [alert_message('live-pengaturan-tr069-tersimpan'), alert_message('live-pengaturan-tr069-gagal-disimpan')],
        'onu-remote-access-save' => [alert_message('live-pengaturan-remote-access-tersimpan'), alert_message('live-pengaturan-remote-access-gagal-disimpan')],
        'onu-veip-save' => [alert_message('live-pengaturan-veip-tersimpan'), alert_message('live-pengaturan-veip-gagal-disimpan')],
        'onu-wan-ip-save' => [alert_message('live-pengaturan-wan-ip-tersimpan'), alert_message('live-pengaturan-wan-ip-gagal-disimpan')],
        'onu-data-service-save' => [alert_message('live-service-tersimpan'), alert_message('live-service-gagal-disimpan')],
        'onu-ethernet-save' => [alert_message('live-pengaturan-port-lan-tersimpan'), alert_message('live-pengaturan-port-lan-gagal-disimpan')],
        'onu-wifi-ssid-save' => [alert_message('live-ssid-wifi-tersimpan'), alert_message('live-ssid-wifi-gagal-disimpan')],
        'onu-wifi-config-save' => [alert_message('live-pengaturan-wifi-tersimpan'), alert_message('live-pengaturan-wifi-gagal-disimpan')],
        'onu-unconfigured-refresh' => [alert_message('live-daftar-onu-belum-terdaftar-diperbarui'), alert_message('live-daftar-onu-belum-terdaftar-gagal-diperbarui')],
        'onu-action' => [alert_message('live-aksi-onu-berhasil-dijalankan'), alert_message('live-aksi-onu-gagal-dijalankan')],
    ];
    [$successMessage, $failureMessage] = $messages[$type] ?? [alert_message('live-perubahan-tersimpan'), alert_message('live-perubahan-gagal-disimpan')];
    if ($type === 'olt-pon-status-save') {
        $successMessage = ($data['state'] ?? '') === 'Disable' ? alert_message('live-pon-berhasil-dimatikan') : alert_message('live-pon-berhasil-dihidupkan');
        $failureMessage = alert_message('live-status-pon-gagal-disimpan');
    } elseif ($type === 'start-olt-discovery') {
        $successMessage = !empty($data['already_running'])
            ? 'Sinkronisasi OLT masih berjalan.' : alert_message('olt-fast-middle-dan-low-sync-olt-masuk-antrean');
        $failureMessage = $code === 'scan_cooldown' ? 'Data sudah terupdate.' : ($code === 'forbidden'
            ? (($data['reason'] ?? '') === 'driver_disabled' ? 'Driver OLT sedang dinonaktifkan.' : alert_message('live-akses-olt-tidak-diizinkan'))
            : alert_message('olt-olt-tidak-ditemukan'));
    } elseif ($type === 'olt-view-refresh') {
        $successMessage = !empty($data['partial'])
            ? alert_message('olt-data-olt-diperbarui-sebagian') : alert_message('olt-data-olt-diperbarui');
        $failureMessage = !empty($data['busy']) ? 'Sinkronisasi OLT masih berjalan.' : alert_message('olt-data-olt-gagal-diperbarui');
        $data['warnings'] = [];
    } elseif ($type === 'olt-port-update') {
        $failureMessage = alert_message('live-pon-gagal-diperbarui');
        $data['port'] = null;
    }
    if (!$ok && !in_array($type, ['start-olt-discovery', 'olt-view-refresh', 'olt-port-update'], true)) {
        $failureMessage = match ($code) {
            'validation' => alert_message('feature-unavailable'),
            'not_found' => alert_message('live-perangkat-tidak-ditemukan'),
            'forbidden' => alert_message('live-akses-perangkat-tidak-diizinkan'),
            'unsupported' => alert_message('feature-unavailable'),
            'device_unreachable' => alert_message('device-unreachable'),
            'device_auth_failed' => alert_message('Device-authentication-failed'),
            'conflict' => alert_message('live-perangkat-sedang-diproses'),
            default => $failureMessage,
        };
    }
    $status = $ok ? 200 : match ($code) {
        'validation' => 422,
        'not_found' => 404,
        'forbidden' => 403,
        'unsupported' => $type === 'olt-port-update' ? 409 : 422,
        'conflict' => $type === 'olt-view-refresh' ? 202 : 409,
        'scan_cooldown' => 409,
        'device_unreachable', 'device_auth_failed', 'device_rejected' => 502,
        default => 500,
    };
    return ['status' => $status, 'payload' => ['ok' => $ok, 'code' => $code, 'message' => $ok ? $successMessage : $failureMessage] + $data];
}

function live_device_action_input_error(string $type, array $input): ?string
{
    $oltId = (int)($input['olt_row_id'] ?? 0);
    $onuId = (int)($input['onu_row_id'] ?? 0);
    $port = (int)($input['port'] ?? 0);
    $kind = trim((string)($input['kind'] ?? ''));
    $index = trim((string)($input['index'] ?? ''));
    $defaultVlan = trim((string)($input['default_vlan'] ?? ''));
    $vlans = preg_replace('/\s+/', '', trim((string)($input['vlans'] ?? '')));
    $vlanValid = ($defaultVlan === '' || ctype_digit($defaultVlan))
        && ($vlans === '' || preg_match('/^\d{1,4}(?:,\d{1,4})*$/D', $vlans) === 1);
    return match ($type) {
        'olt-uplink-save' => $oltId > 0 && trim((string)($input['port_ref'] ?? '')) !== ''
            && is_array(json_decode((string)($input['config'] ?? '{}'), true))
            && is_array(json_decode((string)($input['vlan'] ?? '{}'), true))
                ? null : alert_message('live-konfigurasi-uplink-tidak-valid'),
        'olt-pon-status-save' => $oltId > 0
            && in_array((string)($input['olt_port_enable'] ?? ''), ['Enable', 'Disable'], true)
                ? null : alert_message('live-status-pon-tidak-valid'),
        'olt-pon-flow-control-save' => $oltId > 0 && (int)($input['olt_port_number'] ?? 0) > 0
            && in_array((string)($input['value'] ?? ''), ['Enable', 'Disable'], true)
                ? null : alert_message('live-flow-control-pon-tidak-valid'),
        'olt-profile-save' => $oltId > 0 && $kind !== ''
            && is_array(json_decode((string)($input['data'] ?? '{}'), true))
                ? null : alert_message('live-data-profil-tidak-valid'),
        'olt-profile-delete' => $oltId > 0 && $kind !== ''
            && trim((string)($input['name'] ?? '')) !== '' ? null : alert_message('live-data-profil-tidak-valid'),
        'onu-interface-state-save' => $onuId > 0 && in_array($kind, ['wifi', 'veip'], true)
            && in_array((string)($input['state'] ?? ''), ['lock', 'unlock'], true)
                ? null : alert_message('live-status-antarmuka-tidak-valid'),
        'onu-service-config-delete' => $onuId > 0 && in_array($kind, ['wifi', 'veip', 'tr069'], true)
            ? null : alert_message('live-layanan-onu-tidak-valid'),
        'onu-tr069-save' => $onuId > 0
            && ((string)($input['vlan'] ?? '') === '' || ctype_digit((string)$input['vlan']))
            && ctype_digit((string)($input['priority'] ?? '0'))
                ? null : alert_message('live-vlan-atau-prioritas-tr069-tidak-valid'),
        'onu-remote-access-save' => $onuId > 0 ? null : alert_message('olt-onu-tidak-valid'),
        'onu-veip-save' => $onuId > 0 && $vlanValid ? null : alert_message('live-pengaturan-veip-tidak-valid'),
        'onu-wan-ip-save' => $onuId > 0 && ctype_digit($index) ? null : alert_message('live-service-wan-tidak-valid'),
        'onu-data-service-save' => $onuId > 0 && ctype_digit($index) ? null : alert_message('live-service-tidak-valid'),
        'onu-ethernet-save' => $onuId > 0 && $port > 0 ? null : alert_message('data-port-lan-tidak-valid'),
        'onu-wifi-ssid-save' => $onuId > 0 && $port > 0 ? null : alert_message('live-onu-atau-port-wifi-tidak-valid'),
        'onu-wifi-config-save' => $onuId > 0 && $port > 0 && $vlanValid ? null : alert_message('live-pengaturan-wifi-tidak-valid'),
        'onu-unconfigured-refresh', 'start-olt-discovery', 'olt-view-refresh', 'olt-port-update' => $oltId > 0
            ? null : alert_message('olt-olt-tidak-valid'),
        'onu-action' => $onuId > 0 && trim((string)($input['onu_action'] ?? '')) !== ''
            ? null : alert_message('live-aksi-onu-tidak-valid'),
        default => null,
    };
}

function live_traffic_result(int $userId, string $type, array $input): array
{
    unset($input['csrf']);
    try {
        $resource = in_array($type, ['pon-traffic', 'onu-traffic', 'device-traffic-history'], true)
            ? 'traffic.history.read' : 'live.traffic';
        $result = internal_api_request($userId, $resource, ['type' => $type, 'input' => $input], 60);
    } catch (Throwable $error) {
        error_log('[LINNMS] Live traffic API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal', 'data' => []];
    }
    $ok = !empty($result['ok']);
    if ($ok) return ['status' => 200, 'payload' => ['ok' => true]
        + (is_array($result['data'] ?? null) ? $result['data'] : [])];
    $code = (string)($result['code'] ?? 'internal');
    $message = match ($code) {
        'validation' => in_array($type, ['pon-traffic', 'pon-traffic-live'], true) ? alert_message('olt-olt-tidak-valid')
            : (in_array($type, ['onu-traffic', 'onu-traffic-live'], true) ? alert_message('olt-onu-tidak-valid') : alert_message('live-interface-tidak-valid')),
        'not_found' => $type === 'device-traffic-history' ? alert_message('live-interface-belum-dicatat') : alert_message('live-interface-tidak-ditemukan-pada-perangkat-ini'),
        'unsupported' => in_array($type, ['pon-traffic', 'pon-traffic-live'], true)
            ? alert_message('live-trafik-belum-tersedia-untuk-perangkat-ini') : alert_message('live-trafik-onu-belum-tersedia-untuk-perangkat-ini'),
        'forbidden' => alert_message('live-akun-tidak-aktif'),
        'device_unreachable' => alert_message('live-data-trafik-perangkat-tidak-dapat-dibaca'),
        default => $type === 'device-traffic-record'
            ? alert_message('live-pilihan-interface-tidak-dapat-disimpan') : alert_message('live-data-trafik-tidak-dapat-dimuat'),
    };
    $status = match ($code) {
        'validation' => 422,
        'not_found' => 404,
        'forbidden' => 403,
        'unsupported', 'conflict' => 409,
        'device_unreachable' => 502,
        default => 500,
    };
    return ['status' => $status, 'payload' => ['ok' => false, 'message' => $message]];
}

function live_traffic_input_error(string $type, array $input): ?string
{
    return match ($type) {
        'pon-traffic', 'pon-traffic-live' => (int)($input['olt_row_id'] ?? 0) > 0 ? null : alert_message('olt-olt-tidak-valid'),
        'onu-traffic', 'onu-traffic-live' => (int)($input['onu_row_id'] ?? 0) > 0 ? null : alert_message('olt-onu-tidak-valid'),
        'device-traffic-history' => (int)($input['device_id'] ?? 0) > 0 ? null : alert_message('live-perangkat-tidak-valid'),
        'device-traffic-record' => (int)($input['device_id'] ?? 0) > 0
            && trim((string)($input['if_ref'] ?? '')) !== '' ? null : alert_message('live-interface-tidak-valid'),
        default => null,
    };
}
