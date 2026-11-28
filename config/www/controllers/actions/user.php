<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function linnms_user_action_request(int $uid, string $action, array $data): array
{
    try {
        return internal_api_request($uid, 'user.action', ['action' => $action, 'data' => $data]);
    } catch (Throwable $error) {
        error_log('[LINNMS] User action API: ' . $error->getMessage());
        return ['ok' => false, 'code' => 'internal'];
    }
}

function handle_user_action(string $action): bool
{
    if (!in_array($action, ['create_user', 'toggle_user', 'delete_user', 'reset_password', 'update_profile', 'save_user_device_limits'], true)) {
        return false;
    }
    if (!is_logged_in()) redirect('?page=login');
    $uid = current_user_id();

    if ($action === 'create_user') {
        require_admin_role();
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) !== 1) {
            flash('error', toast_message('user.username-format'));
            redirect('?page=dashboard');
        }
        if ($password === '') {
            flash('error', toast_message('user.password-required'));
            redirect('?page=dashboard');
        }
        $result = linnms_user_action_request($uid, $action, [
            'username' => $username,
            'password' => $password,
            'display_name' => trim((string)($_POST['display_name'] ?? '')),
        ]);
        flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
            ? toast_message('user.created', ['username' => $username])
            : linnms_action_code_message($result, toast_message('user.create-failed')));
        redirect('?page=dashboard');
    }

    if ($action === 'toggle_user' || $action === 'delete_user') {
        require_admin_role();
        $id = valid_positive_id($_POST['user_id'] ?? null);
        if ($id === null) redirect('?page=dashboard');
        $result = linnms_user_action_request($uid, $action, [
            'user_id' => $id,
            'make_active' => (string)($_POST['make_active'] ?? '0'),
        ]);
        if (!empty($result['ok'])) {
            flash('success', $action === 'toggle_user' ? toast_message('user.status-updated') : toast_message('user.deleted'));
        } else {
            flash('error', linnms_action_code_message($result,
                $action === 'toggle_user' ? toast_message('user.status-update-failed') : toast_message('user.delete-failed')));
        }
        redirect('?page=dashboard');
    }

    if ($action === 'reset_password') {
        require_admin_role();
        $id = valid_positive_id($_POST['user_id'] ?? null);
        $password = (string)($_POST['password'] ?? '');
        if ($id === null || $password === '') {
            flash('error', validation_invalid_message('User/password'));
            redirect('?page=dashboard');
        }
        $result = linnms_user_action_request($uid, $action, ['user_id' => $id, 'password' => $password]);
        flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
            ? toast_message('user.password-updated') : linnms_action_code_message($result, toast_message('user.password-update-failed')));
        redirect('?page=dashboard');
    }

    if ($action === 'update_profile') {
        $data = [];
        foreach (['display_name', 'current_password', 'new_password', 'phone_number'] as $key) {
            $data[$key] = (string)($_POST[$key] ?? '');
        }
        $error = null;
        if (trim($data['display_name']) === '' || mb_strlen(trim($data['display_name'])) > 60) {
            $error = toast_message('user.name-invalid');
        } elseif ($data['current_password'] === '') {
            $error = toast_message('user.current-pass-required');
        } elseif ($data['new_password'] !== '' && mb_strlen($data['new_password']) < 6) {
            $error = 'Password baru minimal 6 karakter.';
        } elseif (mb_strlen($data['phone_number']) > 24) {
            $error = 'Kontak maksimal 24 karakter.';
        }
        if ($error !== null) {
            flash('error', $error);
            redirect(public_settings_url() . '#account');
        }
        $result = linnms_user_action_request($uid, $action, $data);
        if (!empty($result['ok'])) {
            $_SESSION['username'] = (string)($result['data']['username'] ?? '');
            $_SESSION['display_name'] = (string)($result['data']['display_name'] ?? '');
            application_log_event($uid, 'Akun', 'update_profile', 'success', linnms_log_message('success', 'user.profile-updated'));
            flash('success', toast_message('user.profile-updated'));
        } else {
            flash('error', linnms_action_reason($result, '') === 'auth' ? 'Pastikan password sesuai'
                : linnms_action_code_message($result, toast_message('user.profile-update-failed')));
        }
        redirect(public_settings_url() . '#account');
    }

    require_admin_role();
    $id = valid_positive_id($_POST['user_id'] ?? null);
    if ($id === null) {
        flash('error', validation_invalid_message('Akun pengguna'));
        redirect(public_page_url('settings-admin') . '#user-limits');
    }
    $data = ['user_id' => $id];
    foreach (['olt_limit', 'mikrotik_limit', 'switch_limit'] as $key) {
        $value = $_POST[$key] ?? null;
        if (!is_scalar($value) || filter_var((string)$value, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]) === false) {
            flash('error', toast_message('user.limit-invalid'));
            redirect(public_page_url('settings-admin') . '#user-limits');
        }
        $data[$key] = (int)$value;
    }
    $result = linnms_user_action_request($uid, $action, $data);
    if (!empty($result['ok'])) {
        application_log_event($uid, 'Akun', 'save_user_device_limits', 'success', linnms_log_message('success', 'user.limits-updated', ['id' => $id]));
    }
    flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
        ? toast_message('user.limits-saved')
        : linnms_action_code_message($result, toast_message('user.limits-failed')));
    redirect(public_page_url('settings-admin') . '#user-limits');
}
