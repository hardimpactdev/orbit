<?php

declare(strict_types=1);

use Orbit\Extensions\Conn\Domain\ConnOperationCompletion;
use Orbit\Extensions\Conn\Domain\ConnRevocationExecution;

$kinds = [];
$kinds['conn.pairing_create'] = [
    'executor' => 'swamp',
    'result_fields' => ['backend', 'pairing_id', 'pairing_link_id', 'expires_at', 'error_code',
        ['name' => 'credential', 'secret' => true, 'store' => 'conn.pairing.credential']],
];
foreach (['pairing_revoke', 'gateway_sessions_revoke'] as $kind) {
    $kinds['conn.'.$kind] = [
        'executor' => 'swamp', 'completion' => ConnOperationCompletion::class, 'execution' => ConnRevocationExecution::class,
        'runs' => [['transport' => 'gateway', 'name' => 'conn.revocation', 'resumable' => true, 'secrets' => ['environment' => 'conn.environment']]],
        'completion_contract' => ['step' => str_replace('_', '-', $kind), 'transport' => 'gateway', 'name' => 'conn.revocation', 'result' => ['backend' => 'revoked']],
        'result_fields' => ConnRevocationExecution::ResultFields,
    ];
}

return $kinds;
