<?php

declare(strict_types=1);

function validation_required_message(string $field): string
{
    return toast_message('validation.required', ['field' => trim($field)]);
}

function validation_port_message(): string
{
    return toast_message('validation.port');
}

function validation_ip_message(): string
{
    return toast_message('validation.ip');
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
