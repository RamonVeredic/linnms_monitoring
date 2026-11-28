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
        'unauthorized' => toast_message('live.session-ended'),
        'forbidden' => toast_message('live.account-inactive'),
        'method_not_allowed' => toast_message('live.method-unsupported'),
        'conflict' => toast_message('live.session-expired'),
        default => toast_message('live.request-unsupported'),
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
        'message' => $code === 'forbidden' ? toast_message('live.account-inactive') : ($code === 'conflict'
            ? toast_message('live.session-expired')
            : ($type === 'device-traffic' ? toast_message('live.device-traffic-failed') : toast_message('live.device-read-failed'))),
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
        'olt-uplink-save' => [toast_message('live.uplink-saved'), toast_message('live.uplink-save-failed')],
        'olt-pon-flow-control-save' => [toast_message('live.flow-control-saved'), toast_message('live.flow-control-failed')],
        'olt-profile-save' => [toast_message('olt.profile-saved'), toast_message('olt.profile-save-failed')],
        'olt-profile-delete' => [toast_message('olt.profile-deleted'), toast_message('olt.profile-delete-failed')],
        'onu-interface-state-save' => [toast_message('live.interface-state-saved'), toast_message('live.interface-state-failed')],
        'onu-service-config-delete' => [toast_message('live.onu-service-deleted'), toast_message('live.service-delete-failed')],
        'onu-tr069-save' => [toast_message('live.tr069-saved'), toast_message('live.tr069-save-failed')],
        'onu-remote-access-save' => [toast_message('live.remote-saved'), toast_message('live.remote-save-failed')],
        'onu-veip-save' => [toast_message('live.veip-saved'), toast_message('live.veip-save-failed')],
        'onu-wan-ip-save' => [toast_message('live.wan-saved'), toast_message('live.wan-save-failed')],
        'onu-data-service-save' => [toast_message('live.service-saved'), toast_message('live.service-save-failed')],
        'onu-ethernet-save' => [toast_message('live.lan-saved'), toast_message('live.lan-save-failed')],
        'onu-wifi-ssid-save' => [toast_message('live.ssid-saved'), toast_message('live.ssid-save-failed')],
        'onu-wifi-config-save' => [toast_message('live.wifi-saved'), toast_message('live.wifi-save-failed')],
        'onu-unconfigured-refresh' => [toast_message('live.uncfg-updated'), toast_message('live.uncfg-refresh-failed')],
        'onu-action' => [toast_message('live.onu-action-ok'), toast_message('live.onu-action-failed')],
    ];
    [$successMessage, $failureMessage] = $messages[$type] ?? [toast_message('live.saved'), toast_message('live.save-failed')];
    if ($type === 'olt-pon-status-save') {
        $successMessage = ($data['state'] ?? '') === 'Disable' ? toast_message('live.pon-disabled') : toast_message('live.pon-enabled');
        $failureMessage = toast_message('live.pon-state-failed');
    } elseif ($type === 'start-olt-discovery') {
        $successMessage = !empty($data['already_running'])
            ? toast_message('olt.sync-running') : toast_message('olt.scan-queued');
        $failureMessage = $code === 'scan_cooldown' ? toast_message('api-olt.current') : ($code === 'forbidden'
            ? (($data['reason'] ?? '') === 'driver_disabled' ? toast_message('live.olt-driver-disabled') : toast_message('access.denied'))
            : toast_message('olt.not-found'));
    } elseif ($type === 'sync-snmp-device') {
        $successMessage = toast_message('live.device-sync-ok');
        $failureMessage = match ($code) {
            'not_found' => toast_message('device.not-found'),
            'forbidden' => toast_message('live.device-driver-disabled'),
            'conflict' => toast_message('request.processing'),
            'device_unreachable' => toast_message('live.snmp-sync-failed'),
            default => toast_message('live.device-sync-failed'),
        };
    } elseif ($type === 'olt-view-refresh') {
        $successMessage = !empty($data['partial'])
            ? toast_message('olt.partial') : toast_message('olt.updated');
        $failureMessage = !empty($data['busy']) ? toast_message('olt.sync-running') : toast_message('olt.update-failed');
        $data['warnings'] = [];
    } elseif ($type === 'olt-port-update') {
        $failureMessage = toast_message('live.pon-update-failed');
        $data['port'] = null;
    }
    if (!$ok && !in_array($type, ['start-olt-discovery', 'sync-snmp-device', 'olt-view-refresh', 'olt-port-update'], true)) {
        $failureMessage = match ($code) {
            'validation' => toast_message('validation.required-fields'),
            'not_found' => toast_message('device.not-found'),
            'forbidden' => toast_message('access.denied'),
            'unsupported' => toast_message('feature.unavailable'),
            'device_unreachable' => toast_message('device.unreachable'),
            'device_auth_failed' => toast_message('device.auth-failed'),
            'conflict' => toast_message('request.processing'),
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
                ? null : validation_invalid_message('Konfigurasi uplink'),
        'olt-pon-status-save' => $oltId > 0
            && in_array((string)($input['olt_port_enable'] ?? ''), ['Enable', 'Disable'], true)
                ? null : validation_invalid_message('Status PON'),
        'olt-pon-flow-control-save' => $oltId > 0 && (int)($input['olt_port_number'] ?? 0) > 0
            && in_array((string)($input['value'] ?? ''), ['Enable', 'Disable'], true)
                ? null : validation_invalid_message('Flow Control PON'),
        'olt-profile-save' => $oltId > 0 && $kind !== ''
            && is_array(json_decode((string)($input['data'] ?? '{}'), true))
                ? null : validation_invalid_message('Data profil'),
        'olt-profile-delete' => $oltId > 0 && $kind !== ''
            && trim((string)($input['name'] ?? '')) !== '' ? null : validation_invalid_message('Data profil'),
        'onu-interface-state-save' => $onuId > 0 && in_array($kind, ['wifi', 'veip'], true)
            && in_array((string)($input['state'] ?? ''), ['lock', 'unlock'], true)
                ? null : validation_invalid_message('Status antarmuka'),
        'onu-service-config-delete' => $onuId > 0 && in_array($kind, ['wifi', 'veip', 'tr069'], true)
            ? null : validation_invalid_message('Layanan ONU'),
        'onu-tr069-save' => $onuId > 0
            && ((string)($input['vlan'] ?? '') === '' || ctype_digit((string)$input['vlan']))
            && ctype_digit((string)($input['priority'] ?? '0'))
                ? null : validation_invalid_message('VLAN atau prioritas TR069'),
        'onu-remote-access-save' => $onuId > 0 ? null : validation_invalid_message('ONU'),
        'onu-veip-save' => $onuId > 0 && $vlanValid ? null : validation_invalid_message('Pengaturan VEIP'),
        'onu-wan-ip-save' => $onuId > 0 && ctype_digit($index) ? null : validation_invalid_message('Service WAN'),
        'onu-data-service-save' => $onuId > 0 && ctype_digit($index) ? null : validation_invalid_message('Service'),
        'onu-ethernet-save' => $onuId > 0 && $port > 0 ? null : validation_invalid_message('Port LAN'),
        'onu-wifi-ssid-save' => $onuId > 0 && $port > 0 ? null : validation_invalid_message('ONU atau port WiFi'),
        'onu-wifi-config-save' => $onuId > 0 && $port > 0 && $vlanValid ? null : validation_invalid_message('Pengaturan WiFi'),
        'sync-snmp-device' => (int)($input['device_row_id'] ?? 0) > 0 ? null : validation_invalid_message('Perangkat'),
        'onu-unconfigured-refresh', 'start-olt-discovery', 'olt-view-refresh', 'olt-port-update' => $oltId > 0
            ? null : validation_invalid_message('OLT'),
        'onu-action' => $onuId > 0 && trim((string)($input['onu_action'] ?? '')) !== ''
            ? null : validation_invalid_message('Aksi ONU'),
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
        'validation' => in_array($type, ['pon-traffic', 'pon-traffic-live'], true) ? validation_invalid_message('OLT')
            : (in_array($type, ['onu-traffic', 'onu-traffic-live'], true) ? validation_invalid_message('ONU') : validation_invalid_message('Interface')),
        'not_found' => $type === 'device-traffic-history' ? toast_message('live.interface-untracked') : toast_message('live.interface-not-found'),
        'unsupported' => in_array($type, ['pon-traffic', 'pon-traffic-live'], true)
            ? toast_message('live.traffic-unavailable') : toast_message('live.onu-traffic-unavailable'),
        'forbidden' => toast_message('live.account-inactive'),
        'device_unreachable' => toast_message('live.device-traffic-failed'),
        default => $type === 'device-traffic-record'
            ? toast_message('live.iface-select-failed') : toast_message('live.traffic-load-failed'),
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
        'pon-traffic', 'pon-traffic-live' => (int)($input['olt_row_id'] ?? 0) > 0 ? null : validation_invalid_message('OLT'),
        'onu-traffic', 'onu-traffic-live' => (int)($input['onu_row_id'] ?? 0) > 0 ? null : validation_invalid_message('ONU'),
        'device-traffic-history' => (int)($input['device_id'] ?? 0) > 0 ? null : validation_invalid_message('Perangkat'),
        'device-traffic-record' => (int)($input['device_id'] ?? 0) > 0
            && trim((string)($input['if_ref'] ?? '')) !== '' ? null : validation_invalid_message('Interface'),
        default => null,
    };
}
