<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationResultProjection;

final readonly class ConnDirectOperations implements DirectOperationContribution
{
    public function definitions(): array
    {
        $kinds = array_fill_keys(['conn.pairing_create', 'conn.gateway_sessions_revoke'], [
            'workflow' => 'operation-conn-direct', 'projection' => DirectOperationResultProjection::class, 'result_hook' => ConnDirectResult::class,
            'private_material' => ['environment' => 'conn.environment'],
        ]);
        $kinds['conn.pairing_create']['secret_outputs'] = ['credential' => 'conn.pairing.credential'];

        $kinds['conn.pairing_revoke'] = ['workflow' => 'operation-conn-pairing-revoke',
            'projection' => DirectOperationResultProjection::class, 'result_hook' => ConnDirectResult::class,
            'workflow_inputs' => true, 'terminal_result' => ['job' => 'finish', 'step' => 'result']];

        return $kinds;
    }
}
