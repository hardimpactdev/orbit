<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Integrations\IntegrationAvailability;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

final readonly class AdmitConnCleanupAction
{
    public function __construct(private StartOperationAction $operations, private NodeAccessAuthorizer $nodes) {}

    public function execute(ConnEnvironment $environment, Node $caller): ?Operation
    {
        IntegrationAvailability::assertKind('conn.gateway_sessions_revoke');
        $gateway = $this->nodes->activeGatewayId() ?? throw new ResourceOperationException('conn.gateway_missing', 'Cleanup needs one active Gateway Node.', 409);

        return DB::transaction(function () use ($environment, $caller, $gateway): ?Operation {
            $environment = ConnEnvironment::query()->lockForUpdate()->findOrFail($environment->id);
            if ($environment->cleanup_version <= $environment->cleanup_completed_version) {
                return null;
            }
            // A pairing admitted before the new session was recorded carries the old one; revoking it now would fail that pairing.
            if (Operation::query()->where('kind', 'conn.pairing_create')->where('input->conn_environment_id', $environment->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('conn.pairing_in_progress', 'A pairing on this server still runs with the earlier session. Clean up after it ends.', 409);
            }

            $reference = ['vault' => 'orbit-control-inputs', 'key' => 'conn-pairing-revoke-'.Str::uuid()];
            $operation = $this->operations->execute('conn.gateway_sessions_revoke', 'conn_environment', $environment->id, $gateway,
                ['conn_environment_id' => $environment->id, 'cleanup_version' => $environment->cleanup_version, 'caller_node_id' => $caller->id,
                    'intent' => ['url' => $environment->url, 'labels' => [RegisterConnEnvironmentAction::GATEWAY_CLIENT_LABEL], 'links' => [], 'revision' => $environment->cleanup_version],
                    'execution_secrets' => ['admin_session' => $reference]], 'operator', null,
                lockKey: OperationStore::lockKey('conn_environment', $environment->id));
            if ($operation->wasRecentlyCreated) {
                app(SwampCommandStep::class)->executionCopy($reference['vault'], $reference['key'], $environment->admin_session);
            }

            return $operation;
        });
    }
}
