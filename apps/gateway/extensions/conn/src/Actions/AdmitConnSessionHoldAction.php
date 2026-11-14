<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Integrations\IntegrationAvailability;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orbit\Extensions\Conn\Domain\ConnHeldSessions;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

/**
 * Admits `conn.session_hold`: the Gateway pairs itself for one Node on a container Instance's T3 server and keeps the session
 * ([Held sessions](/reference/conn#held-sessions)). The server row lock orders every admission for that server, so concurrent first
 * reads of one Node start one Operation: the later read finds the open one and returns it.
 */
final readonly class AdmitConnSessionHoldAction
{
    public function __construct(private StartOperationAction $operations, private NodeAccessAuthorizer $nodes, private ConnHeldSessions $held) {}

    /** The Node's current held session, else its open hold Operation, else a new one. */
    public function hold(Node $node, ConnEnvironment $environment): ConnPairing|Operation
    {
        return $this->admit($node, $environment, null);
    }

    /** A renewal of a held session that nears its end; its projection withdraws the session it renews. */
    public function renew(ConnPairing $current): Operation
    {
        $operation = $this->admit($current->node, $current->environment, $current);
        assert($operation instanceof Operation);

        return $operation;
    }

    private function admit(Node $node, ConnEnvironment $environment, ?ConnPairing $renews): ConnPairing|Operation
    {
        IntegrationAvailability::assertKind(ConnHeldSessions::Kind);
        $gateway = $this->nodes->activeGatewayId() ?? throw new ResourceOperationException('conn.gateway_missing', 'A held session needs one active Gateway Node.', 409);

        return DB::transaction(function () use ($node, $environment, $renews, $gateway): ConnPairing|Operation {
            $environment = ConnEnvironment::query()->lockForUpdate()->findOrFail($environment->id);
            $open = Operation::query()->where('kind', ConnHeldSessions::Kind)->where('input->conn_environment_id', $environment->id)
                ->where('input->caller_node_id', $node->id)->whereNotIn('status', OperationStatus::terminalValues())->first();
            if ($open instanceof Operation) {
                return $open;
            }
            if (! $renews instanceof ConnPairing && ($current = $this->held->current($node, $environment)) instanceof ConnPairing) {
                return $current;
            }
            $this->assertServer($environment);
            $pairing = ConnPairing::query()->create(['node_id' => $node->id, 'conn_environment_id' => $environment->id, 'held' => true,
                'client_label' => $node->name.' via Orbit '.Str::lower(Str::random(8))]);
            $reference = ['vault' => 'orbit-control-inputs', 'key' => 'conn-session-hold-'.Str::uuid()];
            $operation = $this->operations->execute(ConnHeldSessions::Kind, 'conn_pairing', $pairing->id, $gateway, [
                'conn_environment_id' => $environment->id, 'caller_node_id' => $node->id, 'pairing_ids' => [$pairing->id],
                'renews_pairing_id' => $renews?->id,
                'intent' => ['url' => $environment->url, 'environment' => $environment->id, 'pairing_id' => $pairing->id, 'label' => $pairing->client_label,
                    'request_body' => json_encode(['label' => $pairing->client_label, 'scopes' => IssueConnPairingAction::Scopes], JSON_THROW_ON_ERROR)],
                'execution_secrets' => ['admin_session' => $reference],
            ], $renews instanceof ConnPairing ? 'schedule' : 'operator', null);
            if ($operation->wasRecentlyCreated) {
                app(SwampCommandStep::class)->executionCopy($reference['vault'], $reference['key'], $environment->admin_session);
            }

            return $operation;
        });
    }

    /** Only a running container's server is paired; a host server keeps device pairing. */
    private function assertServer(ConnEnvironment $environment): void
    {
        $instance = $environment->instance;
        if ($instance === null) {
            throw new ResourceOperationException('conn.environment_not_instance', "{$environment->label} is not the T3 server of a container Instance; pair with it as a device.", 409);
        }
        // A clone's server is usable from its create's end, before its completion makes the Instance active.
        if ($instance->status === InstanceState::Removing) {
            throw new ResourceOperationException('instance.removal_conflict', 'The Instance is being removed.', 409);
        }
        if ($instance->runtime?->power_state === InstanceRuntimePowerState::Stopped) {
            throw new ResourceOperationException('conn.environment_asleep', "{$environment->label} is asleep. Wake its Instance, then read again.", 409);
        }
        if (! $environment->admin_session_expires_at->isFuture()) {
            throw new ResourceOperationException('conn.session_expired', "The Gateway's admin session on {$environment->label} expired. Renew it first.", 409);
        }
    }
}
