<?php

declare(strict_types=1);

use Orbit\Extensions\Conn\Domain\ConnOperationCompletion;

return (static function (): array {
    $kinds = [];
    foreach (['pairing_create' => 'conn.pairing-issue', 'pairing_revoke' => 'conn.pairing-revoke'] as $kind => $command) {
        $kinds['conn.'.$kind] = [
            'executor' => 'swamp', 'completion' => ConnOperationCompletion::class,
            'runs' => [[
                'transport' => 'gateway', 'name' => $command,
                ...($kind === 'pairing_create' ? ['resumable' => false, 'uncertain_error_code' => 'conn.pairing_outcome_unknown'] : []),
                'input_fields' => ['environment', 'pairing_ids'], 'input_values' => [],
                'secrets' => ['environment' => 'conn.environment'],
                'input_schema' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['environment', 'pairing_ids'], 'properties' => [
                        'environment' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['$secret', 'id'],
                            'properties' => ['$secret' => ['const' => 'conn.environment'], 'id' => ['type' => 'integer', 'minimum' => 1]]],
                        'pairing_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => 10000],
                    ]],
            ]],
            'completion_contract' => ['step' => str_replace('_', '-', $kind), 'transport' => 'gateway', 'name' => $command,
                ...($kind === 'pairing_create' ? ['resumable' => false, 'uncertain_error_code' => 'conn.pairing_outcome_unknown'] : []),
                'result' => ['backend' => $kind === 'pairing_create' ? 'issued' : 'revoked']],
            'result_fields' => ['backend', 'pairing_id', 'pairing_link_id', 'expires_at', 'revoked_sessions', 'error_code',
                ['name' => 'credential', 'secret' => true, 'store' => 'conn.pairing.credential']],
        ];
    }

    return $kinds;
})();
