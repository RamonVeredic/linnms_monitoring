<?php

declare(strict_types=1);

function internal_api_signing_key(): string
{
    $key = trim((string)@file_get_contents('/config/app/data/core/internal.key'));
    if (strlen($key) < 32) throw new RuntimeException('Kunci API internal belum tersedia.');
    return $key;
}

function internal_api_critical_failure(string $message): never
{
    if (PHP_SAPI === 'cli') throw new RuntimeException($message);

    $GLOBALS['LINNMS_ORIGINAL_ERROR_LOGGED'] = true;
    if (!headers_sent()) {
        header_remove('Location');
        http_response_code(500);
        header('Cache-Control: no-store');
        if (str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    exit;
}

function internal_api_inactive_session(string $message): never
{
    if (PHP_SAPI === 'cli') throw new RuntimeException($message);

    if (session_status() !== PHP_SESSION_ACTIVE && session_id() !== '' && !headers_sent()) {
        session_start();
    }
    $acceptsJson = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    if (($_SERVER['SCRIPT_NAME'] ?? '') === '/index.php' && !$acceptsJson
        && function_exists('redirect_invalid_session_to_login') && !headers_sent()) {
        redirect_invalid_session_to_login();
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        session_write_close();
    }
    if (!headers_sent()) {
        http_response_code(401);
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function internal_api_request(int $userId, string $resource, array $params = [], int $timeoutSeconds = 10): array
{
    if ($userId < 0 || ($userId === 0 && !in_array($resource, ['auth.guest', 'guest.page.read', 'app.log.write', 'app.entry.read', 'app.entry.action', 'app.bootstrap.read'], true))) {
        throw new InvalidArgumentException('User API internal tidak valid.');
    }

    $body = json_encode(
        ['resource' => $resource, 'params' => $params],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($body)) throw new RuntimeException('Permintaan API internal tidak valid.');

    $expiresAt = time() + 30;
    $signature = hash_hmac(
        'sha256',
        $userId . "\n" . $expiresAt . "\n" . hash('sha256', $body),
        internal_api_signing_key()
    );

    $curl = curl_init('http://127.0.0.1/internal-api.php');
    if ($curl === false) throw new RuntimeException('Koneksi API internal tidak tersedia.');

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-LINNMS-User: ' . $userId,
            'X-LINNMS-Expires: ' . $expiresAt,
            'X-LINNMS-Signature: ' . $signature,
        ],
        CURLOPT_POSTFIELDS => $body,
    ]);

    $response = curl_exec($curl);
    $error = curl_error($curl);
    unset($curl);

    if (!is_string($response) || $response === '') {
        throw new RuntimeException($error !== '' ? $error : 'Respons API internal kosong.');
    }

    $payload = json_decode($response, true);
    if (!is_array($payload)) throw new RuntimeException('Respons API internal tidak valid.');
    if (($payload['error']['code'] ?? '') === 'session_inactive') {
        internal_api_inactive_session((string)$payload['error']['message']);
    }
    if (($payload['error']['code'] ?? '') === 'database_unavailable') {
        internal_api_critical_failure((string)$payload['error']['message']);
    }
    if (($payload['ok'] ?? false) !== true) {
        throw new RuntimeException((string)($payload['error']['message'] ?? 'Permintaan API internal gagal.'));
    }

    $data = $payload['data'] ?? null;
    return is_array($data) ? $data : [];
}
