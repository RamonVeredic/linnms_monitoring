<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function linnms_onu_registration_redirect(string $page, int $rowId = 0): never
{
    redirect(public_page_url($page, $rowId > 0 ? ['id' => $rowId] : []));
}

function linnms_onu_registration_notices(array $response, string $action): array
{
    $code = linnms_action_reason($response, 'internal');
    $notices = [];
    if (!empty($response['ok'])) {
        if ($action === 'unregister_onu') {
            $notices[] = [
                'level' => $code === 'already_unregistered' ? 'info' : 'success',
                'key' => $code === 'already_unregistered' ? 'reg.already-removed' : 'reg.removed',
            ];
        } elseif ($action === 'replace_onu_registration') {
            $notices[] = ['level' => 'success', 'key' => 'reg.replaced'];
        } else {
            if ($code === 'partial') $notices[] = ['level' => 'warning', 'key' => 'reg.details-partial'];
            $notices[] = ['level' => 'success', 'key' => 'reg.registered'];
        }
    } else {
        $invalidField = match ($code) {
            'onu_invalid' => 'ONU',
            'manual_identity_invalid' => 'OLT, PON, atau serial pra-registrasi',
            'wan_method_invalid' => 'Metode WAN',
            'replacement_invalid' => 'Serial pengganti',
            default => null,
        };
        if ($invalidField !== null) {
            return [['level' => 'error', 'key' => 'validation.invalid', 'values' => ['field' => $invalidField]]];
        }
        $key = match ($code) {
            'name_required' => 'reg.name-required',
            'pon_not_found' => 'reg.pon-missing',
            'onu_not_found' => $action === 'replace_onu_registration'
                ? 'reg.pair-missing' : 'onu.not-found',
            'already_registered' => 'reg.already-registered',
            'serial_unavailable' => $action === 'replace_onu_registration'
                ? 'reg.new-serial-missing' : 'reg.serial-missing',
            'olt_missing' => 'reg.olt-missing',
            'olt_identity_mismatch' => 'reg.olt-mismatch',
            'driver_identity_mismatch' => 'reg.driver-mismatch',
            'service_required' => 'reg.service-required',
            'service_mode_invalid' => 'reg.service-mode',
            'lan_ports_invalid' => 'reg.lan-ports',
            'lan_port_number_invalid' => 'reg.lan-port',
            'outer_vlan_required' => 'reg.outer-vlan',
            'multiple_untag' => 'reg.one-untag',
            'service_index_invalid' => 'reg.index-invalid',
            'duplicate_vlan' => 'reg.vlan-duplicate',
            'wan_service_required' => 'reg.wan-service-required',
            'wan_service_one_required' => 'reg.wan-first-service',
            'pppoe_credentials_required' => 'reg.pppoe-required',
            'static_ip_invalid' => 'reg.static-ip-invalid',
            'service_wan_invalid' => 'reg.wan-service-invalid',
            'tr069_service_required' => 'reg.tr069-service',
            'acs_required' => 'reg.acs-required',
            'acs_credentials_pair' => 'reg.acs-pair',
            'batch_unsupported' => 'reg.batch-unsupported',
            'service_activate_required' => 'reg.service-activate',
            'type_unsupported' => 'reg.type-unsupported',
            'onu_id_unsupported' => 'reg.id-unsupported',
            'manual_service_required' => 'reg.manual-service',
            'rename_unsupported' => 'reg.rename-unsupported',
            'registration_unverified' => 'reg.unverified',
            'rename_failed' => 'reg.rename-failed',
            'profile_after_device' => 'reg.profile-unsaved',
            'profile_rolled_back' => 'reg.profile-rollback',
            'unregister_failed' => 'reg.remove-failed',
            'olt_mismatch' => 'reg.replacement-olt',
            'replacement_registered' => 'reg.replacement-used',
            'replacement_register_failed' => 'reg.replacement-failed',
            'current_unregister_failed' => 'reg.old-remove-failed',
            'replacement_rename_failed' => 'reg.new-rename-failed',
            'profile_transfer_failed' => 'reg.transfer-failed',
            'forbidden' => 'access.denied',
            default => 'onu.register-failed',
        };
        $notices[] = [
            'level' => in_array($code, ['already_registered', 'replacement_registered'], true) ? 'warning' : 'error',
            'key' => $key,
        ];
    }
    return $notices;
}

function handle_onu_registration_action(string $action): bool
{
    if (!in_array($action, ['register_onu', 'pre_register_onu', 'unregister_onu', 'replace_onu_registration'], true)) return false;
    if (!is_logged_in()) redirect('?page=login');
    $rowId = valid_positive_id($_POST['onu_row_id'] ?? null) ?? 0;
    $data = $_POST;
    unset($data['action'], $data['csrf']);
    try {
        $result = internal_api_request(current_user_id(), 'onu.registration.action',
            ['action' => $action, 'data' => $data], 180);
    } catch (Throwable $error) {
        error_log('[LINNMS] ONU registration API: ' . $error->getMessage());
        $result = ['ok' => false, 'code' => 'internal'];
    }
    $code = (string)linnms_action_reason($result, 'internal');
    $resultData = is_array($result['data'] ?? null) ? $result['data'] : [];
    $targetRowId = (int)($resultData['row_id'] ?? $rowId);
    $values = ['number' => (int)($resultData['number'] ?? 0)];
    foreach (linnms_onu_registration_notices($result, $action) as $notice) {
        flash($notice['level'], toast_message($notice['key'], ($notice['values'] ?? []) + $values));
    }
    if (!empty($result['ok'])) {
        linnms_onu_registration_redirect($action === 'unregister_onu' ? 'add-onu' : 'view-onu', $targetRowId);
    }
    if ($action === 'unregister_onu') {
        linnms_onu_registration_redirect($code === 'onu_invalid' || $code === 'onu_not_found' ? 'onus' : 'view-onu', $targetRowId);
    }
    if ($action === 'replace_onu_registration') {
        linnms_onu_registration_redirect($targetRowId > 0 ? 'view-onu' : 'onus', $targetRowId);
    }
    if ($code === 'already_registered' || $code === 'profile_after_device') {
        linnms_onu_registration_redirect('view-onu', $targetRowId);
    }
    linnms_onu_registration_redirect('add-onu');
}
