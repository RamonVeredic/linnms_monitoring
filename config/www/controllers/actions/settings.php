<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function linnms_settings_action_request(int $uid, string $action, array $data): array
{
    try {
        return internal_api_request($uid, 'settings.action', ['action' => $action, 'data' => $data]);
    } catch (Throwable $error) {
        error_log('[LINNMS] Settings action API: ' . $error->getMessage());
        return ['ok' => false, 'code' => 'internal'];
    }
}

function handle_settings_action(string $action): bool
{
    $actions = [
        'save_notifications', 'save_notification_setting', 'save_notification_devices',
        'save_numeric_setting', 'save_sync_timing_settings', 'save_device_driver_support',
        'refresh_device_access', 'run_update',
    ];
    if (!in_array($action, $actions, true)) return false;
    if (!is_logged_in()) redirect('?page=login');
    $uid = current_user_id();

    if ($action === 'run_update') {
        flash('warning', toast_message('settings.docker-update'));
        redirect(public_settings_url() . '#upgrade');
    }

    if ($action === 'save_notifications') {
        $returnPage = action_return_page($_POST['return_page'] ?? '', '?page=notif', ['notification', 'alerts']);
        $data = [];
        foreach (['notify_onu_state', 'notify_onu_signal', 'notify_onu_problem', 'notify_onu_details',
            'notify_device_status', 'notify_interface_changes', 'notify_pon_fault', 'notify_pppoe_status',
            'signal_threshold_dbm', 'problem_threshold'] as $key) {
            if (array_key_exists($key, $_POST)) $data[$key] = $_POST[$key];
        }
        $result = linnms_settings_action_request($uid, $action, $data);
        flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
            ? toast_message('settings.alerts-saved')
            : linnms_action_code_message($result, toast_message('settings.alerts-save-failed')));
        redirect($returnPage);
    }

    if ($action === 'save_notification_setting') {
        $key = trim((string)($_POST['setting_key'] ?? ''));
        $definitions = [
            'problem_threshold' => [toast_message('settings.rx-limit'), 'number'],
            'signal_threshold_dbm' => ['RX Power', 'number'],
            'notify_onu_signal' => ['RX Power', 'toggle'],
            'notify_onu_state' => ['Status ONU', 'toggle'],
            'notify_device_status' => ['Device', 'toggle'],
            'notify_interface_changes' => ['Interface', 'toggle'],
            'notify_pon_fault' => ['PON Fault', 'toggle'],
            'notify_pppoe_status' => ['PPPoE', 'toggle'],
            'notify_onu_problem' => ['Bermasalah', 'toggle'],
            'notify_onu_details' => ['Aktivitas ONU', 'toggle'],
        ];
        $definition = $definitions[$key] ?? null;
        $raw = trim((string)($_POST['setting_value'] ?? ''));
        $error = null;
        if (!is_array($definition)) $error = toast_message('settings.notification-invalid');
        elseif ($definition[1] === 'number') {
            if ($raw === '' || !is_numeric($raw)) $error = toast_message('settings.number-required', ['field' => $definition[0]]);
            elseif (!is_finite((float)$raw)) {
                $error = $definition[0] . ' harus berupa angka yang valid.';
            }
        }
        if ($error !== null) linnms_action_result(false, $error, public_page_url('notification') . '#notification-settings');
        $result = linnms_settings_action_request($uid, $action, ['setting_key' => $key, 'setting_value' => $raw]);
        linnms_action_result(!empty($result['ok']), !empty($result['ok'])
            ? toast_message('settings.saved', ['field' => $definition[0]])
            : linnms_action_code_message($result, toast_message('settings.notify-failed')),
            public_page_url('notification') . '#notification-settings');
    }

    if ($action === 'save_notification_devices') {
        $ids = $_POST['notification_device_ids'] ?? [];
        if (!is_array($ids)) {
            linnms_action_result(false, toast_message('settings.devices-invalid'), public_page_url('notification') . '#notification-devices');
        }
        $result = linnms_settings_action_request($uid, $action, ['notification_device_ids' => $ids]);
        linnms_action_result(!empty($result['ok']), !empty($result['ok'])
            ? toast_message('settings.alert-devices-saved')
            : linnms_action_code_message($result, toast_message('settings.alert-devices-failed')),
            public_page_url('notification') . '#notification-devices');
    }

    require_admin_role();
    if ($action === 'save_numeric_setting') {
        $key = trim((string)($_POST['setting_key'] ?? ''));
        $raw = trim((string)($_POST['setting_value'] ?? ''));
        $definitions = [
            'sync_fast_interval_minutes' => ['Interval Fast Sync', 'sync'],
            'olt_graph_interval_minutes' => ['Interval Sampel Trafik OLT', 'sync'],
            'sync_discovery_interval_minutes' => ['Interval Low Sync', 'sync'],
            'sync_middle_interval_minutes' => ['Interval Middle Sync', 'sync'],
            'sync_fast_deadline_seconds' => [toast_message('settings.fast-limit'), 'sync'],
            'sync_discovery_deadline_seconds' => [toast_message('settings.low-limit'), 'sync'],
            'sync_middle_deadline_seconds' => [toast_message('settings.middle-limit'), 'sync'],
            'snmp_request_timeout_ms' => [toast_message('settings.snmp-timeout'), 'sync'],
            'onu_missing_scan_limit' => [toast_message('settings.onu-missing-limit'), 'onu-missing'],
            'reader_count' => ['Jumlah Reader', 'sync'],
            'fast_sync_onu_batch_size' => ['ONU per Batch Fast Sync', 'sync'],
            'writer_count' => ['Jumlah Writer', 'sync'],
        ];
        $definition = $definitions[$key] ?? null;
        if (!is_array($definition)) {
            flash('error', toast_message('settings.selection-invalid'));
            redirect(public_settings_url() . '#sync');
        }
        [$label, $anchor] = $definition;
        if (preg_match('/^-?\d+$/', $raw) !== 1) {
            flash('error', toast_message('settings.integer-required', ['field' => $label]));
            redirect(public_settings_url() . '#' . $anchor);
        }
        $result = linnms_settings_action_request($uid, $action, ['setting_key' => $key, 'setting_value' => $raw]);
        $code = (string)linnms_action_reason($result, 'internal');
        flash(!empty($result['ok']) ? ($code === 'unchanged' ? 'info' : 'success') : 'error',
            !empty($result['ok']) ? ($code === 'unchanged' ? toast_message('settings.unchanged', ['field' => $label]) : toast_message('settings.saved', ['field' => $label]))
                : linnms_action_code_message($result, toast_message('settings.save-failed', ['field' => $label])));
        redirect(public_settings_url() . '#' . $anchor);
    }

    if ($action === 'save_sync_timing_settings') {
        $data = [];
        foreach (['sync_fast_interval_minutes', 'sync_discovery_interval_minutes', 'sync_middle_interval_minutes',
            'sync_fast_deadline_seconds', 'sync_discovery_deadline_seconds', 'sync_middle_deadline_seconds',
            'snmp_request_timeout_ms'] as $key) {
            if (array_key_exists($key, $_POST)) $data[$key] = $_POST[$key];
        }
        $result = linnms_settings_action_request($uid, $action, $data);
        flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
            ? toast_message('settings.sync-saved')
            : linnms_action_code_message($result, toast_message('settings.sync-save-failed')));
        redirect(public_settings_url() . '#sync');
    }

    if ($action === 'save_device_driver_support') {
        $result = linnms_settings_action_request($uid, $action, [
            'driver_support' => $_POST['driver_support'] ?? [],
            'api_olt_type_support' => $_POST['api_olt_type_support'] ?? [],
        ]);
        linnms_action_result(!empty($result['ok']), !empty($result['ok'])
            ? toast_message('settings.devices-saved')
            : linnms_action_code_message($result, toast_message('settings.device-enable-failed')),
            public_settings_url() . '#drivers');
    }

    $result = linnms_settings_action_request($uid, $action, []);
    $code = (string)linnms_action_reason($result, 'internal');
    if ($code === 'inactive') flash('warning', toast_message('settings.access-inactive'));
    elseif ($code === 'unchanged') flash('warning', toast_message('change.unchanged'));
    elseif (!empty($result['ok'])) flash('success', toast_message('settings.access-updated'));
    else flash('error', linnms_action_code_message($result, toast_message('settings.access-update-failed')));
    redirect(public_settings_url() . '#drivers');
}
