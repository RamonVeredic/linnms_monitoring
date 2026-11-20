<?php

declare(strict_types=1);

function dispatch_device_action(string &$action): void
{
    if ($action === 'add_device') {
        unset($_SESSION['old_snmp_device'], $_SESSION['old_olt'], $_SESSION['device_add_errors']);
        $submittedDriverId = trim((string)($_POST['driver_id'] ?? ''));
        $submittedApiOltType = str_starts_with($submittedDriverId, 'api_olt_type:')
            ? substr($submittedDriverId, strlen('api_olt_type:'))
            : '';
        if ($submittedApiOltType !== '' && array_key_exists($submittedApiOltType, selectable_api_olt_type_options())) {
            $_POST['olt_type'] = $submittedApiOltType;
            $_POST['driver_id'] = 'external_olt_api';
            $submittedDriver = monitoring_driver_definition('external_olt_api');
        } else {
            $submittedApiOltType = '';
            $submittedDriver = monitoring_driver_definition($submittedDriverId);
        }
        $submittedGroup = is_array($submittedDriver) ? (string)($submittedDriver['group'] ?? '') : '';
        $deviceAddErrors = [];
        if (!is_array($submittedDriver) || !in_array($submittedGroup, ['olt', 'router', 'switch'], true)) {
            $deviceAddErrors['driver_id'] = $submittedDriverId === '' ? 'Isi bidang ini.' : toast_message('device.type-invalid');
        }
        if (trim((string)($_POST['device_name'] ?? '')) === '') {
            $deviceAddErrors['device_name'] = 'Isi bidang ini.';
        }
        $submittedIp = trim((string)($_POST['ip_address'] ?? ''));
        if ($submittedIp === '') {
            $deviceAddErrors['ip_address'] = 'Isi bidang ini.';
        } elseif ($submittedApiOltType === '' && filter_var($submittedIp, FILTER_VALIDATE_IP) === false) {
            $deviceAddErrors['ip_address'] = validation_ip_message();
        }
        if (is_array($submittedDriver)) {
            $submittedPort = trim((string)($_POST['snmp_port'] ?? ''));
            if ($submittedPort === '') {
                $deviceAddErrors['snmp_port'] = 'Isi bidang ini.';
            } elseif (!ctype_digit($submittedPort) || (int)$submittedPort < 1 || (int)$submittedPort > 65535) {
                $deviceAddErrors['snmp_port'] = toast_message('device.port-invalid');
            }
            if (trim((string)($_POST['community'] ?? '')) === '') {
                $deviceAddErrors['community'] = 'Isi bidang ini.';
            }
            if (!empty($submittedDriver['telnet_cli']) && (string)($_POST['hybrid_status'] ?? '') === '1') {
                $submittedCliPort = trim((string)($_POST['ports'] ?? ''));
                if ($submittedCliPort === '') {
                    $deviceAddErrors['ports'] = 'Isi bidang ini.';
                } elseif (!ctype_digit($submittedCliPort) || (int)$submittedCliPort < 1 || (int)$submittedCliPort > 65535) {
                    $deviceAddErrors['ports'] = toast_message('device.port-invalid');
                }
                if (trim((string)($_POST['username'] ?? '')) === '') $deviceAddErrors['username'] = 'Isi bidang ini.';
                if (trim((string)($_POST['password'] ?? '')) === '') $deviceAddErrors['password'] = 'Isi bidang ini.';
            }
        }
        if ($deviceAddErrors !== []) {
            if (!empty($submittedDriver['telnet_cli']) && (string)($_POST['hybrid_status'] ?? '') === '1'
                && !isset($deviceAddErrors['password'])) {
                $deviceAddErrors['password'] = toast_message('device.password-reenter');
            }
            $oldDeviceInput = $_POST;
            unset($oldDeviceInput['csrf'], $oldDeviceInput['password'], $oldDeviceInput['community']);
            $_SESSION[$submittedGroup === 'router' || $submittedGroup === 'switch' ? 'old_snmp_device' : 'old_olt'] = $oldDeviceInput;
            $_SESSION['device_add_errors'] = $deviceAddErrors;
            flash('error', toast_message('device.form-check'));
            redirect('?page=dashboard&add_device=1');
        }
        if (is_array($submittedDriver) && in_array((string)($submittedDriver['group'] ?? ''), ['router','switch'], true)) {
            unset($_SESSION['old_olt']);
            $action = (string)$submittedDriver['group'] === 'router' ? 'add_mikrotik' : 'add_switch';
            $_POST['action'] = $action;
        } else {
            unset($_SESSION['old_snmp_device']);
            $action = 'add_olt';
            $_POST['action'] = $action;
            $_POST['olt_name'] = trim((string)($_POST['device_name'] ?? ''));
        }
    }

    if ($action === 'add_mikrotik' || $action === 'add_switch') {
        $driverId = trim((string)($_POST['driver_id'] ?? ''));
        $definition = monitoring_driver_definition($driverId);
        $group = is_array($definition) ? (string)($definition['group'] ?? '') : '';
        $expectedGroup = $action === 'add_mikrotik' ? 'router' : 'switch';
        if (!is_logged_in()) redirect('?page=login');
        if ($group !== $expectedGroup) {
            flash('error', toast_message('device.type-invalid'));
            redirect('?page=dashboard');
        }
        $addPage = '?page=dashboard&add_device=1';
        $viewPage = $group === 'router' ? 'view-mikrotik' : 'view-switch';
        $publicDeviceLabel = $group === 'router' ? 'MikroTik' : 'Switch';
        try {
            $requestData = $_POST;
            unset($requestData['csrf']);
            $created = internal_api_request(current_user_id(), 'device.action',
                ['action' => $action, 'data' => $requestData], 90);
            if (empty($created['ok']) || (int)($created['data']['id'] ?? 0) < 1) {
                throw new RuntimeException(toast_message('device.add-failed-generic'));
            }
            unset($_SESSION['old_snmp_device']);
            flash('success', toast_message('device.added', ['device' => $publicDeviceLabel]));
            redirect('?page=' . $viewPage . '&id=' . (int)$created['data']['id']);
        } catch (Throwable $e) {
            if (!isset($created)) application_log_error($e, $action);
            $_SESSION['old_snmp_device'] = [
                'driver_id' => $driverId,
                'device_name' => trim((string)($_POST['device_name'] ?? '')),
                'ip_address' => trim((string)($_POST['ip_address'] ?? '')),
                'snmp_port' => trim((string)($_POST['snmp_port'] ?? '161')),
            ];
            flash('error', toast_message('device.add-failed', ['device' => $publicDeviceLabel]));
            redirect($addPage);
        }
    }

    if ($action === 'delete_device') {
        if (!is_logged_in()) redirect('?page=login');
        $rowId = valid_positive_id($_POST['device_id'] ?? null);
        try {
            $result = $rowId !== null ? internal_api_request(current_user_id(), 'device.action',
                ['action' => $action, 'data' => ['device_id' => $rowId]], 30) : [];
            $deleted = !empty($result['ok']);
            if (!$deleted) throw new RuntimeException(toast_message('device.delete-unconfirmed'));
            flash('success', toast_message('device.deleted'));
        } catch (Throwable $e) {
            application_log_error($e, $action);
            flash('error', toast_message('device.delete-failed'));
        }
        redirect('?page=dashboard');
    }

    if ($action === 'update_device') {
        $rowId = valid_positive_id($_POST['device_row_id'] ?? null);
        $actorId = current_user_id();
        try {
            $editContext = $rowId !== null ? internal_api_request($actorId, 'page.shell.read',
                ['page' => 'device-edit-form', 'row_id' => $rowId]) : [];
        } catch (Throwable $e) {
            application_log_error($e, $action);
            flash('error', toast_message('device.load-failed'));
            redirect('?page=dashboard');
        }
        $editRow = is_array($editContext['editable_device'] ?? null) ? $editContext['editable_device'] : null;
        $editKind = is_array($editRow) ? device_edit_kind($editRow) : '';
        if ($rowId === null || $editKind === '') {
            flash('error', toast_message('device.not-found'));
            redirect('?page=dashboard');
        }

        $editRowId = (int)($editRow['id'] ?? $rowId);
        $editPageUrl = public_page_url('dashboard', ['edit_device' => $editRowId]);
        $currentDriverId = trim((string)device_driver_type($editRow));
        $_POST['driver_id'] = $currentDriverId;
        $publicDeviceLabel = match ($editKind) {
            'olt_snmp', 'olt_api' => 'OLT',
            default => (string)($editRow['device_type'] ?? '') === 'mikrotik' ? 'MikroTik' : 'Switch',
        };
        $viewUrl = match ($editKind) {
            'olt_snmp' => public_page_url('view-olt', ['id' => $editRowId]),
            'olt_api' => public_page_url('view-olt', ['id' => $editRowId]),
            default => public_page_url(
                $publicDeviceLabel === 'MikroTik' ? 'view-mikrotik' : 'view-switch',
                ['id' => $editRowId]
            ),
        };
        $oldEditInput = $_POST;
        unset($oldEditInput['password']);
        try {
            $requestData = $_POST;
            unset($requestData['csrf']);
            $result = internal_api_request($actorId, 'device.action',
                ['action' => 'update_device', 'data' => $requestData], 90);
        } catch (Throwable $e) {
            application_log_error($e, $action);
            $_SESSION['old_edit_device'] = $oldEditInput;
            flash(
                'error',
                $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : toast_message('device.update-failed', ['device' => $publicDeviceLabel])
            );
            redirect($editPageUrl);
        }

        if (empty($result['ok'])) {
            $_SESSION['old_edit_device'] = $oldEditInput;
            flash(
                'error',
                (string)($result['data']['public_message'] ?? (toast_message('device.update-failed', ['device' => $publicDeviceLabel])))
            );
            redirect($editPageUrl);
        }

        unset($_SESSION['old_edit_device']);
        flash('success', (string)($result['data']['public_message'] ?? (toast_message('device.updated', ['device' => $publicDeviceLabel]))));
        redirect($viewUrl);
    }
}
