<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function linnms_telegram_action_request(int $uid, string $action, array $data): array
{
    try {
        return internal_api_request($uid, 'telegram.action', ['action' => $action, 'data' => $data], 45);
    } catch (Throwable $error) {
        error_log('[LINNMS] Telegram action API: ' . $error->getMessage());
        return ['ok' => false, 'code' => 'internal'];
    }
}

function handle_telegram_action(string $action): bool
{
    if (!in_array($action, ['save_telegram_user_bot', 'save_telegram',
        'telegram_toggle_notifications', 'telegram_disable'], true)) return false;
    if (!is_logged_in()) redirect('?page=login');
    $uid = current_user_id();

    if ($action === 'save_telegram_user_bot') {
        $token = trim((string)($_POST['telegram_bot_token'] ?? ''));
        $chat = trim((string)($_POST['telegram_chat_id'] ?? ''));
        if ($chat !== '' && preg_match('/^-?\d{1,24}$/', $chat) !== 1) {
            flash('error', alert_message('telegram-chat-id-harus-berupa-angka'));
            redirect('?page=telegram');
        }
        $result = linnms_telegram_action_request($uid, $action, [
            'telegram_bot_token' => $token,
            'telegram_chat_id' => $chat,
        ]);
        $code = (string)linnms_action_reason($result, 'internal');
        if (!empty($result['ok'])) {
            flash('success', alert_message('telegram-koneksi-telegram-tersimpan'));
        } elseif ($code === 'unsupported') flash('warning', alert_message('telegram-telegram-belum-tersedia'));
        elseif ($code === 'token_required') flash('error', alert_message('telegram-token-bot-wajib-diisi'));
        elseif ($code === 'chat_invalid') flash('error', alert_message('telegram-chat-id-harus-berupa-angka'));
        elseif ($code === 'device_auth_failed') flash('error', alert_message('telegram-token-bot-tidak-valid'));
        elseif ($code === 'device_rejected') flash('error', alert_message('telegram-bot-telegram-tidak-tersedia'));
        else flash('error', alert_message('telegram-koneksi-telegram-gagal-disimpan'));
        redirect('?page=telegram');
    }

    if ($action === 'save_telegram') {
        $returnPage = action_return_page($_POST['return_page'] ?? '', '?page=notif', ['notification', 'telegram', 'alerts']);
        $data = [];
        foreach (['notify_onu_state', 'notify_onu_signal', 'notify_onu_problem', 'notify_onu_details',
            'notify_device_status', 'notify_interface_changes', 'notify_pppoe_status',
            'signal_threshold_dbm', 'problem_threshold'] as $key) {
            if (array_key_exists($key, $_POST)) $data[$key] = $_POST[$key];
        }
        $result = linnms_telegram_action_request($uid, $action, $data);
        if (linnms_action_reason($result, '') === 'unsupported') flash('warning', alert_message('telegram-telegram-belum-tersedia'));
        else flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
            ? alert_message('telegram-pengaturan-notifikasi-telegram-tersimpan') : alert_message('telegram-pengaturan-notifikasi-telegram-gagal-disimpan'));
        redirect($returnPage);
    }

    if ($action === 'telegram_toggle_notifications') {
        $returnPage = action_return_page($_POST['return_page'] ?? '', '?page=notif', ['notification', 'settings-admin']);
        $enabled = (string)($_POST['telegram_enabled'] ?? '0') === '1';
        $result = linnms_telegram_action_request($uid, $action, ['telegram_enabled' => $enabled ? '1' : '0']);
        $unsupported = linnms_action_reason($result, '') === 'unsupported';
        linnms_action_result(!empty($result['ok']), $unsupported ? alert_message('telegram-telegram-belum-tersedia')
            : (!empty($result['ok']) ? ($enabled ? alert_message('telegram-notifikasi-telegram-diaktifkan') : alert_message('telegram-notifikasi-telegram-dimatikan'))
                : alert_message('telegram-notifikasi-telegram-gagal-diubah')), $returnPage, $unsupported ? 'warning' : 'error');
    }

    if ($action === 'telegram_disable') {
        $result = linnms_telegram_action_request($uid, $action, []);
        flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
            ? alert_message('telegram-koneksi-telegram-dihapus') : alert_message('telegram-koneksi-telegram-gagal-dihapus'));
        redirect('?page=telegram');
    }

    return false;
}
