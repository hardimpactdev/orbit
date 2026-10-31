<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Integrations\IntegrationAvailability;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orbit\Extensions\Vault\Domain\VaultException;
use Orbit\Extensions\Vault\Domain\VaultOperationSecrets;
use SensitiveParameter;

/** Records public intent and the shared native credential descriptor. */
final readonly class StartVaultOperationAction
{
    public function __construct(private StartOperationAction $operations, private VaultOperationSecrets $secrets, private NodeAccessAuthorizer $nodes) {}

    /** @param array<string, mixed> $configuration */
    public function execute(string $action, #[SensitiveParameter] array $configuration, string $trigger = 'operator'): Operation
    {
        $nodeId = $this->nodes->activeGatewayId() ?? throw new VaultException('vault.gateway_missing', 'Vault needs one active Gateway Node.', 409);

        IntegrationAvailability::assertKind('vault.'.$action);

        return DB::transaction(function () use ($nodeId, $action, $configuration, $trigger): Operation {
            $operation = $this->operations->execute('vault.'.$action, 'vault', $nodeId, $nodeId,
                ($action === 'sync' ? $this->syncInput($configuration) : ['configuration' => ['$secret' => 'vault.credentials', 'id' => $nodeId]]), $trigger, null);
            if ($operation->wasRecentlyCreated) {
                if ($action === 'sync') {
                    $reference = ValidatedData::object(ValidatedData::object($operation->input['execution_secrets'])['configuration']);
                    app(SwampCommandStep::class)->executionCopy(ValidatedData::string($reference['vault']),
                        ValidatedData::string($reference['key']), json_encode($configuration, JSON_THROW_ON_ERROR));
                } else {
                    $this->secrets->store($operation, [...$configuration, 'ca_file' => rtrim(Config::string('orbit.home'), '/').'/ca/root.pem']);
                }
            }

            return $operation;
        });
    }

    /** @param array<string,mixed> $configuration
     * @return array<string,mixed>
     */
    private function syncInput(#[SensitiveParameter] array $configuration): array
    {
        $intent = Arr::only($configuration, ['url', 'client_id', 'organization_id', 'collection_id', 'ledger']);
        $intent['ca_file'] = '/usr/local/share/ca-certificates/orbit-root.crt';
        $intent['wanted'] = array_map(static fn (mixed $row): array => Arr::only(ValidatedData::object($row), ['source', 'fingerprint']),
            is_array($configuration['wanted'] ?? null) ? $configuration['wanted'] : []);

        return ['intent' => $intent, 'execution_secrets' => ['configuration' => ['vault' => 'orbit-control-inputs', 'key' => 'vault-sync-'.Str::uuid()]]];
    }
}
