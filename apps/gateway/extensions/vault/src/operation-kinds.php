<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use Orbit\Extensions\Vault\Domain\VaultOperationCompletion;

return (static function (): array {
    if (! OperationKinds::supports('list_of')) {
        return [];
    }
    $kinds = [];
    foreach (['setup', 'sync', 'teardown'] as $action) {
        $kinds['vault.'.$action] = [
            'executor' => 'swamp',
            'completion' => VaultOperationCompletion::class,
            'runs' => [['resumable' => true,
                'transport' => 'remote_program', 'name' => 'vault.collection',
                'input_fields' => ['configuration'], 'input_values' => ['action' => $action],
                'secrets' => ['configuration' => 'vault.credentials'],
                'input_schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['action', 'configuration'],
                    'properties' => [
                        'action' => ['const' => $action],
                        'configuration' => ['type' => 'object'],
                    ],
                ],
            ]],
            'completion_contract' => [
                'step' => 'vault-'.$action,
                'transport' => 'remote_program', 'name' => 'vault.collection',
                'result' => ['outcome' => 'succeeded'],
            ],
            'result_fields' => [
                'outcome', 'error_code', 'data.backend', 'data.error_code', 'data.email',
                'data.organization_id', 'data.organization_name', 'data.collection_id', 'data.collection_name',
                ...array_map(static fn (string $field): array => ['name' => 'data.'.$field, 'type' => 'list', 'items' => 'string', 'max' => 10000],
                    ['sources', 'cipher_ids', 'revisions', 'fingerprints']),
            ],
        ];
    }

    return $kinds;
})();
