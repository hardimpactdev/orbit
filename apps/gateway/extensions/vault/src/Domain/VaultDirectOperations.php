<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationResultProjection;

final readonly class VaultDirectOperations implements DirectOperationContribution
{
    public function definitions(): array
    {
        return array_fill_keys(['vault.sync'], [
            'workflow' => 'operation-vault-direct', 'writer_config' => 'swamp.direct_writers',
            'writer_state' => 'vault_writer', 'projection' => DirectOperationResultProjection::class, 'result_hook' => VaultDirectResult::class,
            'private_material' => ['configuration' => 'vault.credentials'],
        ]);
    }
}
