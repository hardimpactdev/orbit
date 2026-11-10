<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Integrations\IntegrationAvailability;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

final readonly class RevokeConnPairingsAction
{
    public function __construct(private StartOperationAction $operations, private NodeAccessAuthorizer $nodes) {}

    public function execute(Node $node, ConnEnvironment $environment, Node $caller, ?int $pairingId = null): Operation
    {
        IntegrationAvailability::assertKind('conn.pairing_revoke');
        $gateway = $this->nodes->activeGatewayId() ?? throw new ResourceOperationException('conn.gateway_missing', 'Pairing needs one active Gateway Node.', 409);

        return DB::transaction(function () use ($node, $environment, $caller, $gateway, $pairingId): Operation {
            $environment = ConnEnvironment::query()->lockForUpdate()->findOrFail($environment->id);
            if (! $environment->admin_session_expires_at->isFuture()) {
                throw new ResourceOperationException('conn.session_expired', 'The Gateway admin session has expired.', 409);
            }
            $all = ConnPairing::query()->where('node_id', $node->id)->where('conn_environment_id', $environment->id)->orderBy('id')->get();
            if ($pairingId !== null && ! $all->contains('id', $pairingId)) {
                abort(404);
            }
            $pairings = $all->filter(fn (ConnPairing $pairing): bool => $pairingId === null ? $pairing->revoked_at === null : $pairing->id === $pairingId)->values();
            foreach ($pairings as $pairing) {
                if ($pairing->expires_at === null) {
                    continue;
                }
                foreach ($all as $other) {
                    if ($other->id !== $pairing->id && $other->expires_at !== null
                        && ($pairing->issue_started_at ?? $pairing->created_at)->lte($other->expires_at)
                        && ($other->issue_started_at ?? $other->created_at)->lte($pairing->expires_at)) {
                        throw new ResourceOperationException('conn.revocation_ambiguous', 'Pairing issue windows overlap for this Node and environment; no sessions were revoked.', 409);
                    }
                }
            }
            $reference = ['vault' => 'orbit-control-inputs', 'key' => 'conn-pairing-revoke-'.Str::uuid()];
            $operation = $this->operations->execute('conn.pairing_revoke', 'conn_pairing_node', $node->id, $gateway,
                ['conn_environment_id' => $environment->id, 'pairing_ids' => $pairings->pluck('id')->all(),
                    'pairing_links' => $pairings->pluck('pairing_link_id')->all(),
                    'subject_node_id' => $node->id, 'caller_node_id' => $caller->id,
                    'intent' => ['url' => $environment->url, 'labels' => [],
                        'pairings' => $pairings->filter(fn (ConnPairing $pairing): bool => $pairing->expires_at !== null)->map(fn (ConnPairing $pairing): array => [
                            'id' => $pairing->id, 'label' => $pairing->client_label, 'ipAddress' => $node->wireguard_ip,
                            'issuedAfter' => ($pairing->issue_started_at ?? $pairing->created_at)->toJSON(),
                            'expiresAt' => $pairing->expires_at?->toJSON(), 'delivered' => $pairing->credential === null,
                        ])->values()->all(),
                        'links' => $pairings->pluck('pairing_link_id')->filter()->values()->all()],
                    'execution_secrets' => ['admin_session' => $reference]], 'operator', null, lockKey: OperationStore::lockKey('conn_environment', $environment->id));
            if ($operation->wasRecentlyCreated) {
                app(SwampCommandStep::class)->executionCopy($reference['vault'], $reference['key'], $environment->admin_session);
            }

            return $operation;
        });
    }
}
