<?php

declare(strict_types=1);

function toast_message_catalog(): array
{
    static $messages = null;
    if ($messages !== null) return $messages;

    $messages = [];
    $root = realpath(LINNMS_UI_ROOT) ?: LINNMS_UI_ROOT;
    $paths = array_unique([
        dirname($root) . '/app/default/toats_message.txt',
        '/config/app/default/toats_message.txt',
    ]);
    foreach ($paths as $path) {
        if (!is_readable($path)) continue;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) continue;
            [$key, $message] = $parts;
            if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $key) === 1 && preg_match('//u', $message) === 1) {
                $messages[$key] = $message;
            }
        }
    }
    return $messages;
}

function toast_message(string $key, array $values = []): string
{
    $messages = toast_message_catalog();
    $message = $messages[$key] ?? $messages['request.unavailable'] ?? $key;
    $replacements = [];
    foreach ($values as $name => $value) $replacements['{' . $name . '}'] = (string)$value;
    return strtr($message, $replacements);
}
