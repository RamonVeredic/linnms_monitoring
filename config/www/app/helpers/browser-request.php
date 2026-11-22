<?php

declare(strict_types=1);

function linnms_browser_security_settings(): array
{
    return require '/config/app/config/service.php';
}

function linnms_browser_origin(string $value): ?array
{
    $parts = parse_url($value);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
        return null;
    }

    return [
        'scheme' => $parts['scheme'],
        'host' => strtolower($parts['host']),
        'port' => $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80),
    ];
}

function linnms_browser_request_allowed(array $request, array $settings): bool
{
    $agent = (string)($request['HTTP_USER_AGENT'] ?? '');
    if (($settings['browser_only'] ?? true)
        && (preg_match('~^Mozilla/5\.0\b.*\b(?:Firefox|Chrome|Chromium|Safari|Edg|OPR)/[0-9]~i', $agent) !== 1
            || preg_match('~bot\b|crawler|spider|headless|lighthouse|curl/|wget/|python-requests|postmanruntime~i', $agent) === 1)) {
        return false;
    }

    $host = strtolower(trim((string)($request['HTTP_HOST'] ?? '')));
    $proxyHosts = array_map(static fn(string $value): string => strtolower(trim($value)), (array)($settings['proxy_hosts'] ?? []));
    $usesProxyHost = in_array($host, $proxyHosts, true);
    $trustedDomains = array_map(static fn(string $value): string => strtolower(trim($value)),
        (array)($settings['trusted_domains'] ?? []));
    $trustedHost = in_array($host, $trustedDomains, true);
    if ($trustedDomains !== [] && !$trustedHost && !$usesProxyHost) return false;

    $method = strtoupper((string)($request['REQUEST_METHOD'] ?? 'GET'));
    $site = strtolower(trim((string)($request['HTTP_SEC_FETCH_SITE'] ?? '')));
    if (in_array($method, ['GET', 'HEAD'], true)) {
        if (!in_array($site, ['cross-site', 'same-site'], true)) return true;
        return ($request['SCRIPT_NAME'] ?? '') === '/index.php'
            && ($request['HTTP_SEC_FETCH_MODE'] ?? '') === 'navigate'
            && ($request['HTTP_SEC_FETCH_DEST'] ?? '') === 'document';
    }

    if ($site !== '' && $site !== 'same-origin') return false;
    $origin = trim((string)($request['HTTP_ORIGIN'] ?? ''));
    if ($origin === '' || $origin === 'null') {
        return $site === 'same-origin' && (!$usesProxyHost || $trustedHost);
    }

    $source = linnms_browser_origin($origin);
    if ($source === null) return false;
    $https = !empty($request['HTTPS']) && strtolower((string)$request['HTTPS']) !== 'off';
    if ($https && $source['scheme'] !== 'https') return false;

    if (($trustedHost || $trustedDomains === []) && linnms_browser_origin($source['scheme'] . '://' . $host) === $source) {
        return true;
    }
    if (!$usesProxyHost) return false;

    foreach ($trustedDomains as $domain) {
        if (linnms_browser_origin($source['scheme'] . '://' . $domain) === $source) return true;
    }
    return false;
}

function linnms_require_browser_request(): void
{
    if (linnms_browser_request_allowed($_SERVER, linnms_browser_security_settings())) return;

    require_once __DIR__ . '/toast-message.php';
    $message = toast_message('access.denied');
    http_response_code(403);
    header('Cache-Control: no-store');
    if (($_SERVER['SCRIPT_NAME'] ?? '') !== '/index.php') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    exit;
}
