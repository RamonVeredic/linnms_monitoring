<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function linnms_external_olt_action_request(int $uid, string $action, array $data = []): array
{
    try {
        return internal_api_request($uid, 'external_olt.action', ['action' => $action, 'data' => $data], 75);
    } catch (Throwable $error) {
        error_log('[LINNMS] External OLT action API: ' . $error->getMessage());
        return ['ok' => false, 'code' => 'internal'];
    }
}

function handle_api_action(string $action): bool
{
    if (!in_array($action, ['test_api', 'add_olt', 'update_api_olt', 'scan_olt', 'reboot_onu'], true)) return false;
    $uid = current_user_id();

    if ($action === 'test_api') {
        $returnPage = action_return_page($_POST['return_page'] ?? '', public_settings_url(), ['settings', 'settings-admin']);
        $result = linnms_external_olt_action_request($uid, $action);
        if (linnms_action_reason($result, '') === 'api_unavailable') {
            flash('error', toast_message('api-olt.account-unavailable'));
            redirect(public_settings_url());
        }
        $message = match ((string)linnms_action_reason($result, 'internal')) {
            'ok' => 'Koneksi OLT External API aktif.',
            'network' => toast_message('api-olt.unreachable'),
            'auth' => toast_message('api-olt.access-denied'),
            'server' => toast_message('api-olt.server-error'),
            default => linnms_action_code_message($result, toast_message('api-olt.connection-failed')),
        };
        flash(!empty($result['ok']) ? 'success' : 'error', $message);
        redirect($returnPage);
    }

    if ($action === 'add_olt' || $action === 'update_api_olt') {
        $name = trim((string)($_POST['olt_name'] ?? ''));
        $ip = trim((string)($_POST['ip_address'] ?? ''));
        $port = trim((string)($_POST['snmp_port'] ?? ''));
        $community = trim((string)($_POST['community'] ?? ''));
        $type = trim((string)($_POST['olt_type'] ?? ''));
        $rowId = valid_positive_id($_POST['olt_row_id'] ?? null);
        $returnPage = $action === 'add_olt' ? '?page=dashboard&add_device=1'
            : ($rowId !== null ? public_page_url('dashboard', ['edit_device' => $rowId]) : '?page=dashboard');
        $errors = [];
        if ($name === '') $errors[] = validation_required_message('Nama perangkat');
        if ($ip === '') $errors[] = validation_required_message('IP / Host');
        if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) $errors[] = validation_port_message();
        if ($community === '' && $action === 'add_olt') $errors[] = validation_required_message('Community');
        if ($action === 'add_olt' && ($type === '' || !array_key_exists($type, olt_type_options()))) {
            $errors[] = 'Jenis perangkat harus dipilih dari daftar.';
        }
        if ($errors !== []) {
            foreach ($errors as $error) flash('error', $error);
            if ($action === 'add_olt') {
                $_SESSION['old_olt'] = $_POST;
                unset($_SESSION['old_olt']['password'], $_SESSION['old_olt']['csrf']);
            }
            redirect($returnPage);
        }
        $result = linnms_external_olt_action_request($uid, $action, [
                'olt_row_id' => $rowId,
                'olt_name' => $name,
                'ip_address' => $ip,
                'port' => (int)$port,
                'community' => $community,
                'olt_type' => $type,
        ]);
        if (!empty($result['ok'])) {
            $device = mb_substr($name, 0, 120);
            $message = $action === 'add_olt'
                ? linnms_log_message('success', 'api-olt.added', ['device' => $device])
                : linnms_log_message('success', 'api-olt.connection-updated', ['device' => $device]);
            application_log_event($uid, 'OLT', $action, 'success', $message);
            if ($action === 'add_olt') unset($_SESSION['old_olt']);
            flash('success', $action === 'add_olt' ? toast_message('olt.added') : toast_message('api-olt.connection-updated'));
            redirect('?page=dashboard');
        }
        if ($action === 'add_olt') {
            $_SESSION['old_olt'] = $_POST;
            unset($_SESSION['old_olt']['password'], $_SESSION['old_olt']['csrf']);
        }
        $code = (string)linnms_action_reason($result, 'internal');
        if (in_array($code, ['request_unavailable', 'account_limit'], true)) {
            flash('error', device_add_limit_message($code, 'OLT'));
        } elseif ($code === 'api_unavailable') {
            flash('error', toast_message('api-olt.account-unavailable'));
            redirect(public_settings_url());
        } elseif ($code === 'not_found') {
            flash('error', toast_message('api-olt.invalid'));
            redirect('?page=dashboard');
        } elseif ($code === 'validation') {
            flash('error', toast_message('api-olt.connection-invalid'));
        } elseif ($code === 'driver_disabled') {
            flash('error', toast_message('api-olt.driver-disabled'));
        } else {
            flash('error', $action === 'add_olt' ? toast_message('olt.add-failed') : toast_message('api-olt.update-failed'));
        }
        redirect($returnPage);
    }

    if ($action === 'scan_olt') {
        $id = valid_positive_id($_POST['olt_id'] ?? null);
        if ($id === null) {
            flash('error', toast_message('olt.invalid'));
            redirect('?page=dashboard');
        }
        $result = linnms_external_olt_action_request($uid, $action, ['olt_id' => $id]);
        $code = (string)linnms_action_reason($result, 'internal');
        if ($code === 'scan_not_ready') {
            $minutes = max(1, (int)ceil((int)($result['data']['retry_after'] ?? 0) / 60));
            flash('info', toast_message('api-olt.scan-wait', ['minutes' => $minutes]));
            redirect('?page=dashboard');
        }
        if ($code === 'scan_cooldown') {
            flash('info', toast_message('api-olt.current'));
            redirect('?page=dashboard');
        }
        if ($code === 'api_unavailable') {
            flash('error', toast_message('api-olt.account-unavailable'));
            redirect(public_settings_url());
        }
        if ($code === 'success') {
            $count = (int)($result['data']['count'] ?? 0);
            flash('success', toast_message('api-olt.scan-complete', ['count' => $count]));
        } elseif ($code === 'scanning') {
            flash('scanning', toast_message('api-olt.scan-running'));
        } elseif ($code === 'not_found') {
            flash('error', toast_message('olt.invalid'));
        } else {
            flash('error', toast_message('api-olt.scan-failed'));
        }
        redirect('?page=dashboard');
    }

    $id = valid_positive_id($_POST['olt_id'] ?? null);
    $index = trim((string)($_POST['onu_index'] ?? ''));
    if ($id === null || $index === '') {
        flash('error', toast_message('onu.invalid'));
        redirect('?page=onus');
    }
    $result = linnms_external_olt_action_request($uid, $action, ['olt_id' => $id, 'onu_index' => $index]);
    if (linnms_action_reason($result, '') === 'api_unavailable') {
        flash('error', toast_message('api-olt.account-unavailable'));
        redirect(public_settings_url());
    }
    flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
        ? toast_message('api-olt.reboot-sent') : toast_message('api-olt.reboot-failed'));
    redirect('?page=onus');
}
