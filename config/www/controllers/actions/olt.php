<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function handle_olt_action(string $action): bool
{
    $actions = ['approve_api_olt_first_scan', 'add_olt', 'sync_snmp_olt',
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
    if (in_array($action, ['approve_api_olt_first_scan', 'save_olt_card_label'], true)) require_admin_role();

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
            flash('success', alert_message('olt-olt-berhasil-ditambahkan'));
            redirect('?page=dashboard');
        }
        $old = $_POST;
        unset($old['csrf'], $old['password']);
        $_SESSION['old_olt'] = $old;
        $message = match ($code) {
            'name_required' => validation_required_message('Nama perangkat'),
            'ip_invalid' => validation_ip_message(),
            'community_required' => validation_required_message('Community'),
            'port_invalid' => validation_port_message(),
            'username_required' => validation_required_message('Username'),
            'password_required' => validation_required_message('Password'),
            'driver_invalid' => alert_message('olt-driver-olt-snmp-belum-didukung'),
            'driver_disabled' => alert_message('olt-driver-olt-ini-tidak-diizinkan-atau-sedang-dinonaktifkan'),
            'quota_total' => alert_message('olt-batas-olt-keseluruhan-telah-tercapai'),
            'quota_user' => alert_message('olt-batas-olt-akun-telah-tercapai'),
            default => linnms_action_code_message($result, alert_message('olt-olt-gagal-ditambahkan')),
        };
        flash('error', $message);
        redirect('?page=dashboard&add_device=1');
    }

    if ($action === 'approve_api_olt_first_scan') {
        flash($ok ? 'success' : 'error', $ok
            ? 'Scan pertama OLT ' . display_text($payload['name'] ?? null, (string)($payload['olt_id'] ?? ''))
                . ' milik @' . (string)($payload['username'] ?? '-') . ' telah disetujui.'
            : ($code === 'olt_invalid' ? alert_message('olt-permintaan-scan-olt-tidak-valid')
                : alert_message('olt-permintaan-scan-pertama-tidak-ditemukan-atau-sudah-diproses')));
        redirect('?page=dashboard');
    }

    if ($action === 'scan_snmp_olt') {
        $message = $code === 'queued' ? alert_message('olt-fast-middle-dan-low-sync-olt-masuk-antrean')
            : ($code === 'running' ? 'Sinkronisasi OLT masih berjalan.' : alert_message('olt-sinkronisasi-olt-gagal-dijalankan'));
        flash($ok ? 'success' : 'error', $message);
        redirect('?page=dashboard');
    }

    if ($action === 'save_olt_card_label') {
        $label = trim((string)($_POST['card_label'] ?? ''));
        $message = $ok ? ($label === '' ? alert_message('olt-nama-kartu-dihapus') : 'Nama kartu disimpan.')
            : alert_message('olt-nama-kartu-gagal-disimpan');
        flash($ok ? 'success' : 'error', $message);
        redirect($destination);
    }

    if ($action === 'save_olt_cli_connection') {
        $message = $ok ? ($code === 'enabled' ? alert_message('olt-telnet-diaktifkan') : alert_message('olt-telnet-dimatikan'))
            : alert_message('olt-pengaturan-telnet-gagal-disimpan');
        flash($ok ? 'success' : 'error', $message);
        redirect($destination);
    }

    if ($action === 'save_olt_pon_port_config') {
        $fields = olt_pon_port_config_fields();
        $fieldName = (string)($payload['field'] ?? '');
        $label = (string)($fields[$fieldName]['label'] ?? 'Konfigurasi');
        $number = (int)($payload['number'] ?? $pon);
        $message = match ($code) {
            'ok' => alert_message('olt-pon-value-saved', ['label' => $label, 'number' => $number]),
            'unchanged' => alert_message('olt-pon-value-unchanged', ['label' => $label]),
            'config_empty' => alert_message('olt-pon-value-required'),
            'config_invalid' => alert_message('olt-nilai-konfigurasi-pon-tidak-sesuai-pilihan'),
            'unsupported' => alert_message('olt-pon-value-unsupported', ['label' => $label, 'number' => $number]),
            default => linnms_action_code_message($result, alert_message('olt-pon-value-save-failed', ['label' => $label, 'number' => $number])),
        };
        flash($ok ? ($code === 'unchanged' ? 'info' : 'success')
            : (in_array($code, ['config_empty', 'config_invalid', 'unsupported'], true) ? 'warning' : 'error'), $message);
        redirect($destination);
    }

    if ($action === 'save_olt_vlan_members') {
        $count = (int)($payload['applied'] ?? 0);
        $message = $ok ? ($code === 'unchanged' ? alert_message('olt-keanggotaan-vlan-tidak-berubah')
            : alert_message('olt-vlan-changes-applied', ['count' => $count]))
            : ($code === 'partial' ? alert_message('olt-vlan-changes-partial', ['count' => $count])
                : alert_message('olt-perubahan-keanggotaan-vlan-gagal-diterapkan'));
        flash($ok ? 'success' : ($code === 'partial' ? 'warning' : 'error'), $message);
        redirect($destination);
    }

    $message = match ($action) {
        'sync_snmp_olt' => $ok ? alert_message('olt-sinkronisasi-olt-berhasil-dijalankan') : alert_message('olt-sinkronisasi-olt-gagal-dijalankan'),
        'update_snmp_olt_port_description' => $ok ? alert_message('olt-keterangan-pon-tersimpan') : alert_message('olt-keterangan-pon-gagal-disimpan'),
        'save_olt_pon_description_local' => $ok
            ? (trim((string)($_POST['olt_port_description'] ?? '')) === ''
                ? alert_message('olt-pon-description-deleted', ['pon' => $pon])
                : alert_message('olt-pon-description-saved', ['pon' => $pon, 'description' => trim((string)$_POST['olt_port_description'])]))
            : alert_message('olt-keterangan-pon-gagal-disimpan'),
        'refresh_olt' => $ok ? alert_message('olt-data-detail-olt-diperbarui') : alert_message('olt-data-detail-olt-gagal-diperbarui'),
        'refresh_olt_view' => $ok
            ? match ($code) {
                'partial' => alert_message('olt-data-olt-diperbarui-sebagian'),
                'scanning' => alert_message('olt-api-scan-running'),
                default => alert_message('olt-data-olt-diperbarui'),
            } : alert_message('olt-data-olt-gagal-diperbarui'),
        'save_olt_pon_modal' => $ok
            ? (!empty($payload['changed']) ? alert_message('olt-konfigurasi-pon-tersimpan') : alert_message('olt-tidak-ada-perubahan-konfigurasi-pon'))
            : ($code === 'partial' ? alert_message('olt-konfigurasi-pon-tersimpan-sebagian') : alert_message('olt-konfigurasi-pon-gagal-disimpan')),
        'test_olt_cli_connection' => $ok ? alert_message('olt-uji-koneksi-cli-berhasil')
            : alert_message('olt-cli-test-failed', ['detail' => device_connection_message('telnet')]),
        'refresh_olt_cli_optical' => $ok ? alert_message('olt-pembacaan-daya-optik-berhasil') : alert_message('olt-pembacaan-daya-optik-gagal'),
        'refresh_onu_cli_optical' => $ok
            ? alert_message('olt-onu-optical-power-read', ['rx' => onu_rx_power_display($payload['rx_power'] ?? null), 'tx' => format_power($payload['tx_power'] ?? null)])
            : alert_message('olt-daya-optik-onu-gagal-dibaca'),
        'save_olt_profile' => $ok ? alert_message('olt-profil-berhasil-disimpan') : alert_message('olt-profil-gagal-disimpan'),
        'delete_olt_profile' => $ok ? alert_message('olt-profil-berhasil-dihapus') : alert_message('olt-profil-gagal-dihapus'),
        'save_olt_vlan_port' => $ok ? alert_message('olt-konfigurasi-vlan-berhasil-diterapkan') : alert_message('olt-konfigurasi-vlan-gagal-diterapkan'),
        default => linnms_action_code_message($result, alert_message('olt-aksi-olt-belum-tersedia')),
    };
    if ($code === 'olt_invalid') $message = alert_message('olt-olt-tidak-valid');
    if ($code === 'olt_not_found') $message = alert_message('olt-olt-tidak-ditemukan');
    if ($code === 'driver_disabled') $message = alert_message('olt-driver-olt-ini-tidak-diizinkan-atau-sedang-dinonaktifkan');
    if ($code === 'pon_invalid') $message = alert_message('olt-data-pon-tidak-valid');
    if ($code === 'onu_invalid') $message = alert_message('olt-onu-tidak-valid');
    if ($action === 'update_snmp_olt_port_description' && !$ok && $rowId > 0) {
        $destination = '?page=view-olt&id=' . $rowId;
    }
    flash($ok ? ($code === 'partial' ? 'warning' : ($action === 'save_olt_pon_modal' && empty($payload['changed']) ? 'info' : 'success'))
        : ($code === 'partial' ? 'warning' : 'error'), $message);
    redirect($destination);
}
