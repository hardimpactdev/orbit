<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Orbit\Extensions\Conn\Models\ConnPairing;
use SensitiveParameter;

/** One fenced issue effect. A Gateway crash cannot silently create another access link. */
final readonly class ConnPairingIssueCommand implements OperationGatewayCommand
{
    public function __construct(private T3ServerClient $server) {}

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['environment', 'pairing_ids'],
            'properties' => ['environment' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['url', 'admin_session', 'id'],
                'properties' => ['url' => ['type' => 'string'], 'admin_session' => ['type' => 'string'], 'id' => ['type' => 'integer']]],
                'pairing_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 10000]]];
    }

    /** @param array{environment: ConnExecutionInput, pairing_ids: list<int>} $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, #[SensitiveParameter] array $input): array
    {
        $environment = $input['environment']->jsonSerialize();
        $pairing = ConnPairing::query()->whereKey($operation->target_id)
            ->where('conn_environment_id', $environment['id'])->sole();
        if ($operation->kind !== 'conn.pairing_create' || $input['pairing_ids'] !== [$pairing->id]) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This issue does not belong to the pairing.', 422);
        }
        if ($pairing->credential !== null && $pairing->pairing_link_id !== null) {
            return ['backend' => 'issued', 'pairing_id' => $pairing->id, 'pairing_link_id' => $pairing->pairing_link_id,
                'expires_at' => $pairing->expires_at?->toIso8601String(), 'credential' => $pairing->credential];
        }
        if ($pairing->issue_started_at !== null) {
            // The one-time credential cannot be reconstructed from a server-side hash. Never mint again.
            throw new ResourceOperationException('conn.pairing_outcome_unknown', 'The earlier pairing issue has no saved credential. Create a new pairing explicitly.', 409);
        }
        $pairing->forceFill(['issue_started_at' => Carbon::now()])->save();
        $issued = $this->server->issuePairingCredential($environment['url'], $environment['admin_session'], $pairing->client_label);
        $pairing->forceFill(['pairing_link_id' => $issued->id, 'expires_at' => $issued->expiresAt])->save();

        return ['backend' => 'issued', 'pairing_id' => $pairing->id, 'pairing_link_id' => $issued->id,
            'expires_at' => $issued->expiresAt->toIso8601String(), 'credential' => $issued->credential];
    }
}
