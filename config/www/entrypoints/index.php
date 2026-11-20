<?php

declare(strict_types=1);

if (!function_exists('project_path')) { require_once dirname(__DIR__) . '/app/paths.php'; }

require LINNMS_UI_ROOT . '/app/bootstrap.php';
require_once LINNMS_UI_ROOT . '/app/auth.php';
require_once LINNMS_UI_ROOT . '/app/entry.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

unset($_GET['page'], $_GET['id'], $_GET['olt_row'], $_GET['edit']);
$publicRoute = resolve_public_route();
if (!$publicRoute['valid']) {
    http_response_code(404);
    exit;
}
$_GET['page'] = $publicRoute['page'];
if (isset($publicRoute['id'])) $_GET['id'] = $publicRoute['id'];
if (isset($publicRoute['olt_row'])) $_GET['olt_row'] = $publicRoute['olt_row'];
if (isset($publicRoute['edit'])) $_GET['edit'] = $publicRoute['edit'];

require_once LINNMS_UI_ROOT . '/controllers/actions/action-result.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/external-olt.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/user.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/settings.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/telegram.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/whatsapp.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/auth.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/onu.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/onu-registration.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/olt.php';
require_once LINNMS_UI_ROOT . '/controllers/actions/device.php';
require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';
require_once LINNMS_UI_ROOT . '/app/application-log.php';
require_once LINNMS_UI_ROOT . '/controllers/PageController.php';
require_once project_path('view', 'bootstrap.php');

if (linnms_enforce_idle_session()) redirect('?page=login');

register_application_error_logging();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedAction = trim((string)($_POST['action'] ?? ''));
    $guestActions = [
        'login',
        'register_account',
        'register_otp_request',
        'register_otp_cancel',
        'register_otp_verify',
    ];
    if ($submittedAction === 'logout' && !is_logged_in()) {
        logout_user();
    }
    if (!is_logged_in() && !in_array($submittedAction, $guestActions, true)) {
        redirect_invalid_session_to_login();
    }

    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    dispatch_device_action($action);

    if (handle_auth_action($action)) {
        exit;
    }
    if (handle_onu_action($action)) {
        exit;
    }
    if (handle_onu_registration_action($action)) {
        exit;
    }
    if (handle_olt_action($action)) {
        exit;
    }
    if (handle_user_action($action)) {
        exit;
    }
    if (handle_settings_action($action)) {
        exit;
    }
    if (handle_telegram_action($action)) {
        exit;
    }
    if (handle_whatsapp_action($action)) {
        exit;
    }
    handle_api_action($action);
}

if (!is_logged_in()) {
    require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';
    $guestPage = internal_api_request(0, 'guest.page.read');
    $flashes = pull_flashes();
    $publicRegistrationEnabled = !empty($guestPage['public_registration_enabled']);
    $registrationRules = (array)($guestPage['registration_rules'] ?? []);
    $serviceContact = (array)($guestPage['service_contact'] ?? []);
    $otpRegistrationEnabled = $publicRegistrationEnabled;
    $registerPendingPhone = is_array($_SESSION['register_pending'] ?? null)
        ? trim((string)($_SESSION['register_pending']['phone'] ?? ''))
        : '';
    $registerPendingHasOtp = $registerPendingPhone !== ''
        && (int)($_SESSION['register_pending']['otp_challenge'] ?? 0) > 0;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (strtolower(trim((string)($_GET['page'] ?? ''))) === 'register') {
        if (!$publicRegistrationEnabled) redirect('?page=login');
        require project_path('view', 'register.php');
    } else {
        require project_path('view', 'login.php');
    }
    exit;
}

$sessionRead = internal_api_request(current_user_id(), 'session.user.read');
$user = is_array($sessionRead['user'] ?? null) ? $sessionRead['user'] : null;
if ($user === null || (int) $user['is_active'] !== 1) {
    logout_user();
}

$route = PageController::resolve((string) ($_GET['page'] ?? ''));
$page = $route['name'];
if ($page === 'add-device') redirect('?page=dashboard&add_device=1');
if ($page === 'settings-admin' && !is_admin()) redirect(public_page_url('settings'));
$pageTitle = $route['title'];
$pageLead = $route['lead'];
$pageView = PageController::view($page);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';
$pageShell = internal_api_request(current_user_id(), 'page.shell.read', [
    'page' => $page,
    'row_id' => valid_positive_id($_GET['id'] ?? null) ?? 0,
    'notif_page' => (int)($_GET['notif_page'] ?? 1),
    'log_page' => (int)($_GET['log_page'] ?? 1),
]);
$addOnuSidebarTotal = (int)($pageShell['sidebar_unregistered_onus'] ?? 0);
$layoutServiceContact = is_array($pageShell['service_contact'] ?? null) ? $pageShell['service_contact'] : [];
$editDeviceRow = is_array($pageShell['editable_device'] ?? null) ? $pageShell['editable_device'] : null;
$deviceTrafficSelected = is_array($pageShell['traffic_interfaces'] ?? null) ? $pageShell['traffic_interfaces'] : [];
$notificationDevices = is_array($pageShell['notification_devices'] ?? null) ? $pageShell['notification_devices'] : [];
$notificationSelectedIds = $pageShell['notification_selected_ids'] ?? null;
$addOnuPage = [];
if ($page === 'add-onu') {
    require_once LINNMS_UI_ROOT . '/controllers/olt/add-onu.php';
    $addOnuPage = add_onu_page_model(current_user_id());
}

$dashboardRead = null;
if ($page === 'dashboard') {
    require_once LINNMS_UI_ROOT . '/controllers/dashboard.php';
    $dashboardRead = dashboard_read_data(current_user_id(), $_GET);
}

$settings = $dashboardRead !== null
    ? (array)($dashboardRead['settings'] ?? [])
    : (array)($pageShell['user_settings'] ?? []);
$onuProblemSettings = (array)($pageShell['onu_problem_settings'] ?? []);
$telegramServiceSettings = (array)($pageShell['telegram_service_settings'] ?? []);
$telegramFeatureAllowed = !empty($pageShell['telegram_feature_allowed']);
$flashes = pull_flashes();
$serviceAccessState = is_array($pageShell['service_access_state'] ?? null) ? $pageShell['service_access_state'] : [];
$serviceExpiresAt = strtotime((string)($serviceAccessState['claims']['exp'] ?? ''));
$serviceWarningWindowSeconds = 7 * 86400;
$serviceWarningCheckedAt = time();
$serviceExpiryNotice = null;
$serviceExpiryDaysRemaining = null;
$serviceExpiryDateLabel = null;
if (
    $page === 'dashboard'
    && ($serviceAccessState['mode'] ?? '') === 'ready'
    && $serviceExpiresAt !== false
    && $serviceExpiresAt > $serviceWarningCheckedAt
    && ($serviceExpiresAt - $serviceWarningCheckedAt) <= $serviceWarningWindowSeconds
) {
    $serviceExpiryNotice = true;
    $serviceExpiryDaysRemaining = max(
        1,
        (int)ceil(($serviceExpiresAt - $serviceWarningCheckedAt) / 86400)
    );
    $serviceExpiryDateLabel = format_indonesian_date_short($serviceExpiresAt);
}
$stats = [];
$olts = [];
$mikrotikDevices = [];
$switchDevices = [];
$oltQuota = [];
$mikrotikQuota = [];
$switchQuota = [];
$dashboardDeviceRows = [];
if ($dashboardRead !== null) {
    $stats = (array)($dashboardRead['stats'] ?? []);
    $olts = (array)($dashboardRead['olts'] ?? []);
    $mikrotikDevices = (array)($dashboardRead['mikrotikDevices'] ?? []);
    $switchDevices = (array)($dashboardRead['switchDevices'] ?? []);
    $oltQuota = (array)($dashboardRead['oltQuota'] ?? []);
    $mikrotikQuota = (array)($dashboardRead['mikrotikQuota'] ?? []);
    $switchQuota = (array)($dashboardRead['switchQuota'] ?? []);
    $dashboardDeviceRows = (array)($dashboardRead['deviceRows'] ?? []);
    $dashboardDeviceRowCount = (int)($dashboardRead['deviceRowCount'] ?? 0);
    $activeOltScanRows = (array)($dashboardRead['activeOltScanRows'] ?? []);
    $latestOltScanStatuses = (array)($dashboardRead['latestOltScanStatuses'] ?? []);
} else {
    $stats = (array)($pageShell['dashboard_stats'] ?? []);
    $olts = (array)($pageShell['olts'] ?? []);
    $mikrotikDevices = (array)($pageShell['mikrotik_devices'] ?? []);
    $switchDevices = (array)($pageShell['switch_devices'] ?? []);
    $oltQuota = (array)($pageShell['olt_quota'] ?? []);
    $mikrotikQuota = (array)($pageShell['mikrotik_quota'] ?? []);
    $switchQuota = (array)($pageShell['switch_quota'] ?? []);
}
$accountQuotaClaims = [];
if (in_array($page, ['settings', 'settings-admin'], true)) {
    $accountAccessState = $serviceAccessState;
    $accountQuotaClaims = (array)($accountAccessState['claims']['quota'] ?? []);
}
$profileServiceLimits = in_array($page, ['settings', 'settings-admin'], true) ? [
    'olt' => is_admin() ? (int)($accountQuotaClaims['q1'] ?? $oltQuota['limit']) : (int)$oltQuota['user_limit'],
    'mikrotik' => is_admin() ? (int)($accountQuotaClaims['q2'] ?? $mikrotikQuota['limit']) : (int)$mikrotikQuota['user_limit'],
    'switch' => is_admin() ? (int)($accountQuotaClaims['q3'] ?? $switchQuota['limit']) : (int)$switchQuota['user_limit'],
] : [];
$serviceContact = in_array($page, ['settings', 'settings-admin'], true)
    ? $layoutServiceContact
    : ['whatsapp' => '', 'email' => ''];
$onuBackupPreview = null;
if (in_array($page, ['settings', 'settings-admin'], true) && is_array($_SESSION['onu_backup_preview'] ?? null)) {
    $candidatePreview = $_SESSION['onu_backup_preview'];
    $isCurrentUser = (int)($candidatePreview['user_id'] ?? 0) === current_user_id();
    $isFresh = time() - (int)($candidatePreview['created_at'] ?? 0) <= (int)($GLOBALS['linnms_onu_backup_limits']['preview_ttl_seconds'] ?? 0);
    if ($isCurrentUser && $isFresh) $onuBackupPreview = $candidatePreview;
    else unset($_SESSION['onu_backup_preview']);
}
$oltDriverSupportFlags = (array)($pageShell['olt_driver_flags'] ?? []);
$apiOltTypeSupportFlags = (array)($pageShell['api_olt_type_flags'] ?? []);
$snmpDriverSupportFlags = (array)($pageShell['snmp_driver_flags'] ?? []);
$deviceDriverCatalog = monitoring_driver_catalog();
$apiOltTypeOptions = selectable_api_olt_type_options();
$deviceAddModalOpen = $page === 'dashboard' && (
    (string)($_GET['add_device'] ?? '') === '1'
    || !empty($_SESSION['old_snmp_device'])
    || !empty($_SESSION['old_olt'])
    || !empty($_SESSION['device_add_errors'])
);
$settingsAdminTimingFields = [];
if ($page === 'settings-admin') {
    require_once LINNMS_UI_ROOT . '/controllers/settings-page.php';
    $settingsAdminTimingFields = settings_admin_timing_fields($pageShell);
}
$managedDeviceUsers = $page === 'settings-admin' ? (array)($pageShell['managed_device_users'] ?? []) : [];
$oltDetail = null;
$oltSystem = [];
$oltSystemLive = null;
$oltPonRefs = [];
$oltPonModules = [];
$oltCards = [];
$oltPonNotice = '';

$oltPonFocusNumber = null;
$oltOnuStats = [
    'total' => 0,
    'online' => 0,
    'loss' => 0,
    'down' => 0,
    'problem' => 0,
    'power_down' => 0,
    'unregist' => 0,
    'unknown' => 0,
];

$oltViewId = $page === 'view-olt'
    ? valid_positive_id($_GET['id'] ?? null)
    : null;

if ($page === 'view-olt') {
    if ($oltViewId === null) {
        flash('warning', toast_message('olt.not-found'));
        redirect('?page=dashboard');
    }

    $oltSnapshot = (array)($pageShell['olt_snapshot'] ?? []);
    $oltDetail = is_array($oltSnapshot['detail'] ?? null) ? $oltSnapshot['detail'] : null;

    if ($oltDetail === null) {
        flash('warning', toast_message('olt.not-found'));
        redirect('?page=dashboard');
    }
    if (device_row_access_locked($oltDetail)) {
        flash('warning', device_access_lock_message());
        redirect('?page=dashboard');
    }
    $oltPonSnapshot = (array)($oltSnapshot['pon'] ?? []);
    $oltCards = (array)($oltSnapshot['cards'] ?? []);
    $oltProfileSnapshots = (array)($oltSnapshot['profiles'] ?? []);
    $oltOnuTypes = (array)($oltSnapshot['onu_types'] ?? []);
    
    
    $oltPonOpticalSnapshot = $oltPonSnapshot;
    $oltDetail['pon_snapshot'] = $oltPonSnapshot;

    if ((int)($oltDetail['hybrid_status'] ?? 0) === 1 && monitoring_row_uses_handler($oltDetail, 'snmp_olt')) {
        $_SESSION['linnms_direct_realtime']['olt'][(string)$oltDetail['id']] = [
            'user_id' => current_user_id(),
            'row_id' => (int)$oltDetail['id'],
            'device_type' => 'olt',
            'expires_at' => time() + 14400,
        ];
    }

    
    $oltPonFocusNumber = valid_positive_id($_GET['pon'] ?? null);

    if (monitoring_row_uses_handler($oltDetail, 'snmp_olt')) {
        $oltSystem = (array)($oltSnapshot['system'] ?? []);
        $oltSystemLive = [];
        $oltPonModules = (array)($oltSnapshot['modules'] ?? []);
    } elseif (monitoring_row_uses_handler($oltDetail, 'external_api')) {
        $oltSystem = olt_system_snapshot($oltDetail);
        $oltSystemLive = [];
    }

    $oltOnuStats = (array)($oltSnapshot['onu_stats'] ?? []);

    require_once LINNMS_UI_ROOT . '/controllers/olt/view-olt.php';
    extract(olt_view_page_data(current_user_id(), [
        'oltDetail' => $oltDetail,
        'oltSystem' => $oltSystem,
        'oltCards' => $oltCards,
        'oltPonSnapshot' => $oltPonSnapshot,
        'oltPonModules' => $oltPonModules ?? [],
        'oltPonOpticalSnapshot' => $oltPonOpticalSnapshot,
        'oltOnuStats' => $oltOnuStats,
        'oltProfileSnapshots' => $oltProfileSnapshots,
        'oltOnuTypes' => $oltOnuTypes,
        'oltPonFocusNumber' => $oltPonFocusNumber,
    ]), EXTR_OVERWRITE);
}

$deviceDetail = null;
$deviceSystem = [];
$deviceInterfaces = [];
$interfaceRates = [];
$interfaceDetail = null;
$devicePageKind = in_array($page,['view-mikrotik','view-mikrotik-interface'],true) ? 'mikrotik' : ($page === 'view-switch' ? 'switch' : null);
if ($devicePageKind !== null) {
    $deviceRowId = valid_positive_id($_GET['id'] ?? null);
    if ($deviceRowId === null) {
        flash('warning', toast_message('device.not-found'));
        redirect('?page=dashboard');
    }
    try {
        $snapshot = internal_api_request(current_user_id(), 'device.snapshot.read', [
            'row_id' => $deviceRowId,
            'kind' => $devicePageKind,
        ]);
        if (empty($snapshot['ok'])) throw new RuntimeException('Perangkat tidak ditemukan.');
        $inspection = (array)($snapshot['data'] ?? []);
        $deviceDetail = (array)($inspection['device'] ?? []);
        if (device_row_access_locked($deviceDetail)) {
            flash('warning', device_access_lock_message());
            redirect('?page=dashboard');
        }
        $deviceSystem = (array)($inspection['system'] ?? []);
        $deviceInterfaces = (array)($inspection['interfaces'] ?? []);
        $_SESSION['linnms_direct_realtime'][$devicePageKind][(string)$deviceRowId]=[
            'user_id'=>current_user_id(),
            'row_id'=>$deviceRowId,
            'device_type'=>(string)($deviceDetail['device_type']??''),
            'expires_at'=>time()+14400,
        ];
        if($page==='view-mikrotik-interface'){
            $requestedInterface=trim((string)($_GET['interface']??''));
            foreach($deviceInterfaces as $interface){
                if((string)($interface['name']??'')===$requestedInterface){$interfaceDetail=$interface;break;}
            }
            if(!is_array($interfaceDetail)){
                flash('warning',toast_message('interface.mikrotik-not-found'));
                redirect('?page=view-mikrotik&id='.$deviceRowId);
            }
        }
    } catch (Throwable $e) {
        application_log_error($e, $devicePageKind . '_detail');
        flash('warning', toast_message('device.not-found'));
        redirect('?page=dashboard');
    }
}

$onuDetail = null;
$onuLive = null;
$onuSnmpDetail = null;
$onuSnmpDetailMessage = '';
$onuIsProblem = false;
$onuProblemSince = null;
$onuProblemDescription = null;
$onuCliOptical = ['available' => false, 'enabled' => false, 'message' => ''];
$onuViewId = $page === 'view-onu' ? valid_positive_id($_GET['id'] ?? null) : null;

if ($page === 'view-onu' && $onuViewId !== null) {
    $onuSnapshot = (array)($pageShell['onu_snapshot'] ?? []);
    $onuDetail = is_array($onuSnapshot['detail'] ?? null) ? $onuSnapshot['detail'] : null;

    if ($onuDetail !== null) {
        foreach ((array)($onuSnapshot['problem_onus'] ?? []) as $problemOnu) {
            if (
                (string)($problemOnu['source'] ?? '') === (string)($onuDetail['source'] ?? '')
                && (int)($problemOnu['olt_id'] ?? 0) === (int)($onuDetail['olt_id'] ?? 0)
                && (string)($problemOnu['onu_index'] ?? '') === (string)($onuDetail['onu_index'] ?? '')
            ) {
                $onuIsProblem = true;
                $onuProblemSince = $problemOnu['problem_since'] ?? null;
                $onuProblemDescription = $problemOnu['problem_description'] ?? null;
                break;
            }
        }

        $isSnmpOnu = monitoring_row_uses_handler($onuDetail,'snmp_olt');
        
        
        $onuLanPorts = (array)($onuSnapshot['lan_ports'] ?? []);
        $onuLan = [
            'state' => $onuLanPorts !== [] ? 'ok' : 'empty',
            'ports' => $onuLanPorts,
            'captured_at_epoch' => 0,
        ];

        if ($isSnmpOnu) {
            $onuSnmpDetail = [
                'ok' => true,
                'source' => 'database_snapshot',
                'onu_index' => $onuDetail['onu_index'] ?? null,
                'physical' => $onuDetail['physical'] ?? null,
                'name' => $onuDetail['onu_name'] ?? null,
                'onu_serial' => $onuDetail['onu_serial'] ?? null,
                'mac' => $onuDetail['onu_serial'] ?? null,
                'status' => $onuDetail['onu_state'] ?? null,
                'auth_mode' => $onuDetail['auth_mode'] ?? null,
                'rx_power' => $onuDetail['rx_power'] ?? null,
                'tx_power' => $onuDetail['tx_power'] ?? null,
                'temperature' => $onuDetail['temperature'] ?? null,
                'distance' => $onuDetail['distance'] ?? null,
                'rtt' => $onuDetail['rtt'] ?? null,
                'ports' => [],
                'mac_table' => [],
            ];
        }

        if (!$isSnmpOnu) {
            $rawSnapshot = [];
            $rawJson = trim((string)($onuDetail['raw_json'] ?? ''));
            if ($rawJson !== '') {
                $decodedRaw = json_decode($rawJson, true);
                if (is_array($decodedRaw)) {
                    $rawSnapshot = is_array($decodedRaw['parsed'] ?? null)
                        ? $decodedRaw['parsed']
                        : $decodedRaw;
                }
            }
            $onuSnmpDetail = [
                'ok' => true,
                'source' => 'database_snapshot',
                'onu_index' => $onuDetail['onu_index'] ?? null,
                'physical' => $onuDetail['physical'] ?? ($rawSnapshot['pon'] ?? null),
                'name' => $onuDetail['onu_name'] ?? ($rawSnapshot['name'] ?? null),
                'onu_serial' => $onuDetail['onu_serial'] ?? ($rawSnapshot['serial'] ?? $rawSnapshot['mac'] ?? null),
                'mac' => $onuDetail['onu_serial'] ?? ($rawSnapshot['serial'] ?? $rawSnapshot['mac'] ?? null),
                'status' => $onuDetail['onu_state'] ?? null,
                'auth_mode' => $onuDetail['auth_mode'] ?? null,
                'rx_power' => $onuDetail['rx_power'] ?? ($rawSnapshot['rx_power'] ?? null),
                'tx_power' => $onuDetail['tx_power'] ?? ($rawSnapshot['tx_power'] ?? null),
                'temperature' => $onuDetail['temperature'] ?? ($rawSnapshot['temperature'] ?? null),
                'distance' => $onuDetail['distance'] ?? ($rawSnapshot['distance'] ?? null),
                'rtt' => $rawSnapshot['rtt'] ?? null,
                'ports' => is_array($rawSnapshot['ports'] ?? null) ? $rawSnapshot['ports'] : [],
                'mac_table' => [],
            ];
            if ($onuSnmpDetail['rx_power'] === null && $onuSnmpDetail['temperature'] === null) {
                $onuSnmpDetailMessage = 'Detail tambahan belum tersedia melalui metode koneksi ini.';
            }
        }
        $confirmedOnuState = $onuDetail['onu_state'] ?? null;
        if (is_array($onuLive)) {
            $onuLive['status'] = $confirmedOnuState;
            if (is_array($onuLive['onu'] ?? null)) {
                $onuLive['onu']['status'] = $confirmedOnuState;
            }
        }
        if (is_array($onuSnmpDetail)) {
            $onuSnmpDetail['status'] = $confirmedOnuState;
            $onuSnmpDetail['onu_state'] = $confirmedOnuState;
            $onuSnmpDetail['state'] = $confirmedOnuState;
        }

        $onuOltRow = $onuSnapshot['olt_row'] ?? null;
        if (is_array($onuOltRow)) {
            $onuOltDefinition = monitoring_driver_definition((string)device_driver_type($onuOltRow));
            $onuCliOptical['available'] = is_array($onuOltDefinition) && !empty($onuOltDefinition['telnet_cli']);
            $onuCliOptical['enabled'] = (int)($onuOltRow['hybrid_status'] ?? 0) === 1
                && !empty($onuOltRow['cli_password_saved']);
            if (!$onuCliOptical['available']) {
                $onuCliOptical['message'] = 'Driver OLT ini belum mendukung pembacaan daya optik lewat CLI.';
            } elseif (!$onuCliOptical['enabled']) {
                $onuCliOptical['message'] = 'Aktifkan koneksi Telnet (CLI) pada halaman OLT agar daya optik ONU (RX/TX) dapat dibaca.';
            } else {
                $onuCliOptical['message'] = 'Daya optik dibaca lewat perintah CLI perangkat (Telnet).';
            }
        }
    }
}
$whatsappUserSettings = (array)($pageShell['whatsapp_user_settings'] ?? []);
$whatsappGatewayRegistry = $GLOBALS['linnms_whatsapp_providers'];
$whatsappProviders = array_map(static fn(array $provider): string => (string)($provider['label'] ?? ''), $whatsappGatewayRegistry);
$whatsappServiceSettings = (array)($pageShell['whatsapp_service_settings'] ?? []);
$whatsappFeatureAllowed = !empty($pageShell['whatsapp_feature_allowed']);
$notificationView = [];
if ($page === 'notification') {
    require_once LINNMS_UI_ROOT . '/controllers/settings-page.php';
    $notificationView = notification_page_view_data($pageShell, $telegramFeatureAllowed, $whatsappFeatureAllowed);
}
$notificationHistory = (array)($pageShell['notification_history'] ?? []);
$whatsappHistoryPageSize = max(1, (int)($notificationHistory['page_size'] ?? 1));
$whatsappHistoryTotal = $page === 'whatsapp' ? (int)($notificationHistory['total'] ?? 0) : 0;
$whatsappHistoryPages = max(1, (int)ceil($whatsappHistoryTotal / $whatsappHistoryPageSize));
$whatsappHistoryPage = $page === 'whatsapp' ? (int)($notificationHistory['page'] ?? 1) : 1;
$whatsappEvents = $page === 'whatsapp' ? (array)($notificationHistory['rows'] ?? []) : [];
$telegramHistoryPageSize = $whatsappHistoryPageSize;
$telegramHistoryTotal = $page === 'telegram' ? (int)($notificationHistory['total'] ?? 0) : 0;
$telegramHistoryPages = max(1, (int)ceil($telegramHistoryTotal / $telegramHistoryPageSize));
$telegramHistoryPage = $page === 'telegram' ? (int)($notificationHistory['page'] ?? 1) : 1;
$events = $page === 'telegram' ? (array)($notificationHistory['rows'] ?? []) : [];
$activityPageSize = (int)($pageShell['application_logs']['page_size'] ?? 0);
$recentActivityPageSize = 50;
if ($dashboardRead !== null) {
    $recentActivitySearch = (string)($dashboardRead['recentActivitySearch'] ?? '');
    $recentActivityDevice = (string)($dashboardRead['recentActivityDevice'] ?? '');
    $recentActivityTotal = (int)($dashboardRead['recentActivityTotal'] ?? 0);
    $recentActivityPages = max(1, (int)($dashboardRead['recentActivityPages'] ?? 1));
    $recentActivityPage = max(1, (int)($dashboardRead['recentActivityPage'] ?? 1));
    $recentEvents = (array)($dashboardRead['recentEvents'] ?? []);
    $recent = $recentEvents;
    $recentActivityEmptyText = (string)($dashboardRead['recentActivityEmptyText'] ?? 'Belum ada aktivitas terbaru');
} else {
    $recentActivitySearch = '';
    $recentActivityDevice = '';
    $recentActivityTotal = 0;
    $recentActivityPages = 1;
    $recentActivityPage = 1;
    $recentEvents = [];
}
$activityFeed = [];
$activityTotal = 0;
$activityPages = 1;
$activityPage = 1;
if ($page === 'applogs') {
    $applicationLogs = (array)($pageShell['application_logs'] ?? []);
    $activityTotal = (int)($applicationLogs['total'] ?? 0);
    $activityPages = (int)($applicationLogs['pages'] ?? 1);
    $activityPage = (int)($applicationLogs['page'] ?? 1);
    $activityFeed = (array)($applicationLogs['rows'] ?? []);
}

require project_path('view', 'layouts', 'app.php');
