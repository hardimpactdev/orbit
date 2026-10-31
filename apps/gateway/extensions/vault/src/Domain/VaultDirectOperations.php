<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationResultProjection;

final readonly class VaultDirectOperations implements DirectOperationContribution
{
    public function definitions(): array
    {
        return ['vault.sync' => ['workflow' => 'operation-vault-sync', 'projection' => DirectOperationResultProjection::class,
            'result_hook' => VaultDirectResult::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'sync', 'step' => 'result']],
            ...array_fill_keys(['vault.setup', 'vault.teardown'], [
                'workflow' => 'operation-vault-direct', 'projection' => DirectOperationResultProjection::class, 'result_hook' => VaultDirectResult::class,
                'private_material' => ['configuration' => 'vault.credentials'],
            ])];
    }
}
