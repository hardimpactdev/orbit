<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;

return (static function (): array {
    if (! OperationKinds::supports('list_of')) {
        return [];
    }
    $resultFields = [
        'outcome', 'error_code', 'data.backend', 'data.error_code', 'data.email',
        'data.organization_id', 'data.organization_name', 'data.collection_id', 'data.collection_name',
        ...array_map(static fn (string $field): array => ['name' => 'data.'.$field, 'type' => 'list', 'items' => 'string', 'max' => 4096],
            ['sources', 'cipher_ids', 'revisions', 'fingerprints']),
    ];

    return array_fill_keys(['vault.sync', 'vault.setup', 'vault.teardown'], ['executor' => 'swamp', 'result_fields' => $resultFields]);
})();
