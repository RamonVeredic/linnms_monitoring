<?php

declare(strict_types=1);

$timezone = trim((string)(getenv('LINNMS_TIMEZONE') ?: 'Asia/Jakarta'));
date_default_timezone_set(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Asia/Jakarta');
unset($timezone);

require_once dirname(__DIR__, 2) . '/app/config/alert-messages.php';
require_once dirname(__DIR__, 2) . '/app/config/error-messages.php';

foreach ([
    'request', 'auth-session', 'routes', 'drivers', 'device-access',
    'olt-api', 'activity', 'display', 'dates', 'phone', 'olt-snapshot',
    'device-edit', 'device-add', 'validation',
] as $helper) {
    require_once __DIR__ . '/helpers/' . $helper . '.php';
}
