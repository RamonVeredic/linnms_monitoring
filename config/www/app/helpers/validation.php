<?php

declare(strict_types=1);

function validation_required_message(string $field): string
{
    return trim($field) . ' harus diisi.';
}

function validation_port_message(): string
{
    return 'Masukkan port 1–65535.';
}

function validation_ip_message(): string
{
    return 'Periksa IP Anda.';
}

function device_connection_message(string $kind): string
{
    return match (trim($kind)) {
        'snmp' => toast_message('validation.snmp'),
        'telnet' => toast_message('validation.telnet'),
        'api' => toast_message('validation.api'),
        default => toast_message('device.unreachable'),
    };
}

function validation_invalid_message(string $field): string
{
    return toast_message('validation.invalid', ['field' => trim($field)]);
}

function registration_sanitize_display_name(mixed $name): string
{
    $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$name) ?? '';
    $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? '';

    return trim($clean);
}
