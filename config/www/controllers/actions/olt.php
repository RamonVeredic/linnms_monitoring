<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function handle_olt_action(string $action): bool
{
    $actions = ['add_olt', 'sync_snmp_olt',
        'update_snmp_olt_port_description', 'save_olt_pon_description_local',
        'save_olt_card_label', 'refresh_olt', 'refresh_olt_view', 'scan_snmp_olt',
        'save_olt_pon_port_config', 'save_olt_pon_modal', 'save_olt_cli_connection',
        'test_olt_cli_connection', 'refresh_olt_cli_optical', 'refresh_onu_cli_optical',
        'save_olt_profile', 'delete_olt_profile', 'save_olt_vlan_port', 'save_olt_vlan_members',
        'save_zte_vlan', 'delete_zte_vlan'];
    if (!in_array($action, $actions, true)) return false;
    if ($action === 'add_olt') {
        $definition = monitoring_driver_definition(trim((string)($_POST['driver_id'] ?? '')));
        if (is_array($definition) && ($definition['handler'] ?? '') !== 'snmp_olt') return false;
    }
    if (!is_logged_in()) redirect('?page=login');
    if (in_array($action, ['save_olt_card_label'], true)) require_admin_role();

    $rowId = valid_positive_id($_POST['olt_row_id'] ?? null) ?? 0;
    $onuRowId = valid_positive_id($_POST['onu_row_id'] ?? null) ?? 0;
    $pon = (int)($_POST['olt_port_number'] ?? 0);
    $portRef = trim((string)($_POST['olt_port_ref'] ?? ''));
    $kind = trim((string)($_POST['kind'] ?? ''));
    $destination = '?page=dashboard';
    if (in_array($action, ['sync_snmp_olt', 'update_snmp_olt_port_description',
        'save_olt_pon_description_local', 'save_olt_card_label', 'refresh_olt', 'refresh_olt_view',
        'save_olt_pon_port_config', 'save_olt_pon_modal', 'save_olt_cli_connection',
        'test_olt_cli_connection', 'refresh_olt_cli_optical', 'save_olt_profile',
        'delete_olt_profile', 'save_olt_vlan_port', 'save_olt_vlan_members',
        'save_zte_vlan', 'delete_zte_vlan'], true) && $rowId > 0) {
        $destination = '?page=view-olt&id=' . $rowId;
    }
    if (in_array($action, ['update_snmp_olt_port_description', 'save_olt_pon_description_local',
        'save_olt_pon_port_config'], true) && $rowId > 0 && $pon > 0) {
        $destination .= '&pon=' . $pon . '#pon-' . $pon;
    }
    if (in_array($action, ['refresh_olt', 'refresh_olt_view'], true) && $rowId > 0) $destination .= '#olt-pon-config';
    if ($action === 'save_olt_card_label' && $rowId > 0) $destination .= '#olt-pon-cards';
    if (in_array($action, ['save_olt_cli_connection', 'test_olt_cli_connection',
        'refresh_olt_cli_optical'], true) && $rowId > 0) $destination .= '#olt-cli-connection';
    if (in_array($action, ['save_olt_profile', 'delete_olt_profile', 'save_olt_vlan_port',
        'save_olt_vlan_members', 'save_zte_vlan', 'delete_zte_vlan'], true) && $rowId > 0) {
        $destination .= ($kind === 'vlan' || str_contains($action, 'vlan')) ? '#olt-panel-vlans' : '#olt-panel-onu-types';
    }
    if ($action === 'save_olt_pon_modal' && $rowId > 0 && $portRef !== '') {
        $destination .= '&pon_ref=' . rawurlencode($portRef) . '#pon-'
            . preg_replace('~[^a-zA-Z0-9]+~', '-', $portRef);
    }
    if ($action === 'refresh_onu_cli_optical') {
        $destination = $onuRowId > 0 ? '?page=view-onu&id=' . $onuRowId : '?page=onus';
    }

    $data = $_POST;
    unset($data['csrf'], $data['action']);
    try {
        $result = internal_api_request(current_user_id(), 'olt.action',
            ['action' => $action, 'data' => $data], 480);
    } catch (Throwable $error) {
        error_log('[LINNMS] OLT action API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal'];
    }
    $code = (string)linnms_action_reason($result, 'internal');
    $payload = is_array($result['data'] ?? null) ? $result['data'] : [];
    $ok = !empty($result['ok']);

    if ($action === 'add_olt') {
        if ($ok) {
            unset($_SESSION['old_olt']);
            flash('success', toast_message('olt.added'));
            redirect('?page=dashboard');
        }
        $old = $_POST;
        unset($old['csrf'], $old['password']);
        $_SESSION['old_olt'] = $old;
        if ($code === 'snmp_no_response') {
            $_SESSION['device_add_errors']['community'] = toast_message('validation.snmp');
            redirect('?page=dashboard&add_device=1');
        }
        $message = match ($code) {
            'name_required' => validation_required_message('Nama perangkat'),
            'ip_invalid' => validation_ip_message(),
            'community_required' => validation_required_message('Community'),
            'port_invalid' => validation_port_message(),
            'username_required' => validation_required_message('Username'),
            'password_required' => validation_required_message('Password'),
            'driver_invalid' => toast_message('olt.driver-unsupported'),
            'driver_disabled' => toast_message('olt.driver-disabled'),
            'request_unavailable', 'account_limit' => device_add_limit_message($code, 'OLT'),
            default => linnms_action_code_message($result, toast_message('olt.add-failed')),
        };
        flash('error', $message);
        redirect('?page=dashboard&add_device=1');
    }

    if ($action === 'scan_snmp_olt') {
        $message = $code === 'queued' ? 'Fast, Middle, dan Low Sync OLT masuk antrean.'
            : ($code === 'running' ? toast_message('olt.sync-running') : toast_message('olt.sync-start-failed'));
        flash($ok ? 'success' : 'error', $message);
        redirect('?page=dashboard');
    }

    if ($action === 'save_olt_card_label') {
        $label = trim((string)($_POST['card_label'] ?? ''));
        $message = $ok ? ($label === '' ? toast_message('olt.card-label-deleted') : toast_message('olt.card-label-saved'))
            : toast_message('olt.card-label-failed');
        flash($ok ? 'success' : 'error', $message);
        redirect($destination);
    }

    if ($action === 'save_olt_cli_connection') {
        $message = $ok ? ($code === 'enabled' ? toast_message('olt.telnet-enabled') : toast_message('olt.telnet-disabled'))
            : toast_message('olt.telnet-save-failed');
        flash($ok ? 'success' : 'error', $message);
        redirect($destination);
    }

    if ($action === 'save_olt_pon_port_config') {
        $fields = olt_pon_port_config_fields();
        $fieldName = (string)($payload['field'] ?? '');
        $label = (string)($fields[$fieldName]['label'] ?? 'Konfigurasi');
        $number = (int)($payload['number'] ?? $pon);
        $message = match ($code) {
            'ok' => toast_message('olt.pon.saved', ['field' => $label, 'number' => $number]),
            'unchanged' => toast_message('olt.pon.unchanged', ['field' => $label]),
            'config_empty' => toast_message('olt.pon-value-required'),
            'config_invalid' => toast_message('olt.pon-value-invalid'),
            'unsupported' => toast_message('olt.pon.unsupported', ['field' => $label, 'number' => $number]),
            default => linnms_action_code_message($result, toast_message('olt.pon.save-failed', ['field' => $label, 'number' => $number])),
        };
        flash($ok ? ($code === 'unchanged' ? 'info' : 'success')
            : (in_array($code, ['config_empty', 'config_invalid', 'unsupported'], true) ? 'warning' : 'error'), $message);
        redirect($destination);
    }

    if ($action === 'save_olt_vlan_members') {
        $count = (int)($payload['applied'] ?? 0);
        $message = $ok ? ($code === 'unchanged' ? toast_message('olt.vlan-unchanged')
            : toast_message('olt.vlan.membership-applied', ['count' => $count]))
            : ($code === 'partial' ? toast_message('olt.vlan.membership-partial', ['count' => $count])
                : toast_message('olt.vlan-members-failed'));
        flash($ok ? 'success' : ($code === 'partial' ? 'warning' : 'error'), $message);
        redirect($destination);
    }

    $message = match ($action) {
        'sync_snmp_olt' => $ok ? toast_message('olt.sync-started') : toast_message('olt.sync-start-failed'),
        'update_snmp_olt_port_description' => $ok ? toast_message('olt.pon-label-saved') : toast_message('olt.pon-label-failed'),
        'save_olt_pon_description_local' => $ok
            ? (trim((string)($_POST['olt_port_description'] ?? '')) === ''
                ? toast_message('olt.pon.description-deleted', ['number' => $pon])
                : toast_message('olt.pon.description-saved', ['number' => $pon, 'description' => trim((string)$_POST['olt_port_description'])]))
            : toast_message('olt.pon-label-failed'),
        'refresh_olt' => $ok ? toast_message('olt.detail-updated') : toast_message('olt.detail-update-failed'),
        'refresh_olt_view' => $ok
            ? match ($code) {
                'partial' => toast_message('olt.partial'),
                'scanning' => toast_message('olt.api-scanning'),
                default => toast_message('olt.updated'),
            } : toast_message('olt.update-failed'),
        'save_olt_pon_modal' => $ok
            ? (!empty($payload['changed']) ? toast_message('olt.pon-saved') : toast_message('olt.pon-unchanged'))
            : ($code === 'partial' ? toast_message('olt.pon-partial') : toast_message('olt.pon-save-failed')),
        'test_olt_cli_connection' => $ok ? toast_message('olt.cli-test-ok')
            : ($code === 'device_auth_failed' ? toast_message('olt.cli-auth-failed')
                : toast_message('olt.cli-test-failed') . device_connection_message('telnet')),
        'refresh_olt_cli_optical' => $ok ? toast_message('olt.optical-read') : toast_message('olt.optical-failed'),
        'refresh_onu_cli_optical' => $ok
            ? toast_message('olt.optical.read', [
                'rx' => onu_rx_power_display($payload['rx_power'] ?? null),
                'tx' => format_power($payload['tx_power'] ?? null),
            ])
            : toast_message('olt.onu-optical-failed'),
        'save_olt_profile' => $ok ? toast_message('olt.profile-saved') : toast_message('olt.profile-save-failed'),
        'delete_olt_profile' => $ok ? toast_message('olt.profile-deleted') : toast_message('olt.profile-delete-failed'),
        'save_olt_vlan_port' => $ok ? toast_message('olt.vlan-applied') : toast_message('olt.vlan-failed'),
        default => linnms_action_code_message($result, toast_message('olt.action-unavailable')),
    };
    if ($code === 'scan_not_ready') $message = toast_message('api-olt.scan-wait', ['minutes' => max(1, (int)ceil((int)($payload['retry_after'] ?? 0) / 60))]);
    if ($code === 'olt_invalid') $message = toast_message('olt.invalid');
    if ($code === 'olt_not_found') $message = toast_message('olt.not-found');
    if ($code === 'driver_disabled') $message = toast_message('olt.driver-disabled');
    if ($code === 'pon_invalid') $message = toast_message('olt.pon-invalid');
    if ($code === 'onu_invalid') $message = toast_message('onu.invalid');
    if ($action === 'update_snmp_olt_port_description' && !$ok && $rowId > 0) {
        $destination = '?page=view-olt&id=' . $rowId;
    }
    flash($ok ? ($code === 'partial' ? 'warning' : ($action === 'save_olt_pon_modal' && empty($payload['changed']) ? 'info' : 'success'))
        : ($code === 'partial' ? 'warning' : 'error'), $message);
    redirect($destination);
}
