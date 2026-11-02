<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationResultProjection;

final readonly class VaultDirectOperations implements DirectOperationContribution
{
    private const array CliSecrets = ['vault' => 'orbit-control-inputs', 'key_pattern' => '^nckrtl-vault-sync-plan-[a-f0-9-]{36}-(prepare-inputs-inputs-(client_secret|master_password)|session-session-session-value)$'];

    public function definitions(): array
    {
        return ['vault.sync' => ['workflow' => 'operation-vault-sync',
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^vault-sync-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$'], self::CliSecrets], 'projection' => DirectOperationResultProjection::class,
            'result_hook' => VaultDirectResult::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'sync', 'step' => 'result']],
            'vault.setup' => [
                'workflow' => 'operation-vault-setup', 'projection' => VaultSetupResultProjection::class, 'result_hook' => VaultDirectResult::class,
                'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^vault-setup-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$'], self::CliSecrets],
                'workflow_inputs' => true, 'terminal_result' => ['job' => 'setup', 'step' => 'Verify the account'],
            ],
            'vault.teardown' => [
                'workflow' => 'operation-vault-teardown', 'projection' => VaultTeardownResultProjection::class, 'result_hook' => VaultDirectResult::class,
                'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^vault-teardown-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$'], self::CliSecrets],
                'workflow_inputs' => true, 'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm teardown', 'native' => true],
            ]];
    }
}
