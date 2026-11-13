<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceState;
use App\Domain\Integrations\IntegrationAvailability;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

final readonly class IssueConnPairingAction
{
    private const array Scopes = [
        'orchestration:read', 'orchestration:operate', 'settings:write', 'providers:manage',
        'environment:maintain', 'preview:operate', 'diagnostics:read', 'terminal:read',
        'terminal:operate', 'source-control:write', 'filesystem:read', 'filesystem:write',
        'relay:read', 'access:read', 'access:write', 'relay:write',
    ];

    public function __construct(private StartOperationAction $operations, private NodeAccessAuthorizer $nodes) {}

    /** A renewal never brings back a server the Node's newest pairing was revoked on; only an explicit pairing does. */
    public function admit(Node $node, ConnEnvironment $environment, bool $renew = false): Operation
    {
        IntegrationAvailability::assertKind('conn.pairing_create');
        $gateway = $this->nodes->activeGatewayId() ?? throw new ResourceOperationException('conn.gateway_missing', 'Pairing needs one active Gateway Node.', 409);

        return DB::transaction(function () use ($node, $environment, $gateway, $renew): Operation {
            // The lock orders this admission against a renewal's record: it copies either the old session, which the cleanup then
            // waits for, or the new one ([Renewal of an Instance server](/reference/conn#renewal-of-an-instance-server)).
            $environment = ConnEnvironment::query()->lockForUpdate()->findOrFail($environment->id);
            $this->assertSession($environment);
            if ($renew && ConnPairing::query()->where('node_id', $node->id)->where('conn_environment_id', $environment->id)->latest('id')->first()?->revoked_at !== null) {
                throw new ResourceOperationException('conn.pairing_revoked', "This Node's newest pairing on {$environment->label} was revoked, so it is not renewed. Pair again without renew to bring the server back.", 409);
            }
            $pairing = ConnPairing::query()->create(['node_id' => $node->id, 'conn_environment_id' => $environment->id,
                'client_label' => $node->name.' via Orbit '.Str::lower(Str::random(8))]);

            $reference = ['vault' => 'orbit-control-inputs', 'key' => 'conn-pairing-create-'.Str::uuid()];
            $operation = $this->operations->execute('conn.pairing_create', 'conn_pairing', $pairing->id, $gateway,
                ['conn_environment_id' => $environment->id, 'intent' => ['url' => $environment->url, 'pairing_id' => $pairing->id, 'label' => $pairing->client_label,
                    'request_body' => json_encode(['label' => $pairing->client_label, 'scopes' => self::Scopes], JSON_THROW_ON_ERROR)],
                    'execution_secrets' => ['admin_session' => $reference],
                    'pairing_ids' => [$pairing->id], 'caller_node_id' => $node->id], 'operator', null);
            if ($operation->wasRecentlyCreated) {
                app(SwampCommandStep::class)->executionCopy($reference['vault'], $reference['key'], $environment->admin_session);
            }

            return $operation;
        });
    }

    private function assertSession(ConnEnvironment $environment): void
    {
        if ($environment->instance?->status === InstanceState::Removing) {
            throw new ResourceOperationException('instance.removal_conflict', 'The Instance is being removed.', 409);
        }
        if (! $environment->admin_session_expires_at->isFuture()) {
            throw new ResourceOperationException('conn.session_expired', "The Gateway's admin session on {$environment->label} expired. Restart that T3 server so it registers again.", 409);
        }
    }
}
