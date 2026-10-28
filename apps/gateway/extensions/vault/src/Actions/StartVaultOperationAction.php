<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Operation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Vault\Domain\VaultException;
use Orbit\Extensions\Vault\Domain\VaultOperationSecrets;
use SensitiveParameter;

/** Commits secret-free intent and its encrypted credential snapshot together. */
final readonly class StartVaultOperationAction
{
    public function __construct(private StartOperationAction $operations, private VaultOperationSecrets $secrets, private NodeAccessAuthorizer $nodes) {}

    /** @param array<string, mixed> $configuration */
    public function execute(string $action, #[SensitiveParameter] array $configuration, string $trigger = 'operator'): Operation
    {
        $nodeId = $this->nodes->activeGatewayId() ?? throw new VaultException('vault.gateway_missing', 'Vault needs one active Gateway Node.', 409);

        return DB::transaction(function () use ($nodeId, $action, $configuration, $trigger): Operation {
            $operation = $this->operations->execute('vault.'.$action, 'vault', $nodeId, $nodeId,
                ['configuration' => ['$secret' => 'vault.credentials', 'id' => $nodeId]], $trigger, null);
            if ($operation->wasRecentlyCreated) {
                $this->secrets->store($operation, [...$configuration, 'ca_file' => rtrim(Config::string('orbit.home'), '/').'/ca/root.pem']);
            }

            return $operation;
        });
    }
}
