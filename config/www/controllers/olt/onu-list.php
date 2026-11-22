<?php

declare(strict_types=1);

require_once LINNMS_UI_ROOT . '/app/internal-api-client.php';

function onu_list_read_data(
    int $userId, array $filters, int $page, bool $includeLookups = false, int $offset = 0, bool $metadataOnly = false
): array {
    $perPage = $filters['per'] ?? null;
    unset($filters['per']);
    $data = internal_api_request($userId, 'onu.list.read', [
        'filters' => $filters,
        'page' => $page,
        'per' => $perPage,
        'offset' => $offset,
        'metadata_only' => $metadataOnly,
        'include_lookups' => $includeLookups,
    ]);
    $data['hasZte'] = !empty($data['has_zte']);
    return $data;
}
