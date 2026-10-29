<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use Orbit\Extensions\Conn\Domain\ConnOperationCompletion;

return (static function (): array {
    $kinds = [];
    foreach (['pairing_create' => 'conn.pairing-issue', 'pairing_revoke' => 'conn.pairing-revoke'] as $kind => $command) {
        $kinds['conn.'.$kind] = [
            'executor' => 'swamp', 'completion' => ConnOperationCompletion::class,
            'runs' => [[
                'transport' => 'gateway', 'name' => $command,
                ...($kind === 'pairing_create' ? ['resumable' => false, 'uncertain_error_code' => 'conn.pairing_outcome_unknown'] : ['resumable' => true]),
                'input_fields' => ['environment', 'pairing_ids'], 'input_values' => [],
                'secrets' => ['environment' => 'conn.environment'],

            ]],
            'completion_contract' => ['step' => str_replace('_', '-', $kind), 'transport' => 'gateway', 'name' => $command,
                'result' => ['backend' => $kind === 'pairing_create' ? 'issued' : 'revoked']],
            'result_fields' => ['backend', 'pairing_id', 'pairing_link_id', 'expires_at', 'revoked_sessions', 'error_code',
                ...($kind === 'pairing_create' ? [['name' => 'credential', 'secret' => true, 'store' => 'conn.pairing.credential']] : [])],
        ];
    }

    if (! OperationKinds::supports('secret_outputs')) {
        unset($kinds['conn.pairing_create']);
    }

    return $kinds;
})();
