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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orbit\Extensions\Conn\Domain\ConnHeldSessions;
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
            $all = ConnPairing::query()->where('node_id', $node->id)->where('conn_environment_id', $environment->id)->orderBy('id')->lockForUpdate()->get();
            if ($pairingId !== null && ! $all->contains('id', $pairingId)) {
                abort(404);
            }
            // Without an id, the Node's own open pairings, as before held sessions; a held one is revoked by its id
            // ([Held sessions](/reference/conn#held-sessions)).
            $pairings = $all->filter(fn (ConnPairing $pairing): bool => $pairingId === null ? $pairing->revoked_at === null && ! $pairing->held : $pairing->id === $pairingId)->values();
            // A held session is matched by its exact label alone, so a held pairing is never ambiguous and makes no other pairing so
            // ([Held sessions](/reference/conn#held-sessions)).
            foreach ($pairings as $pairing) {
                if ($pairing->expires_at === null || $pairing->held) {
                    continue;
                }
                foreach ($all as $other) {
                    if ($other->id !== $pairing->id && $other->expires_at !== null && ! $other->held
                        && ($pairing->issue_started_at ?? $pairing->created_at)->lte($other->expires_at)
                        && ($other->issue_started_at ?? $other->created_at)->lte($pairing->expires_at)) {
                        throw new ResourceOperationException('conn.revocation_ambiguous', 'Pairing issue windows overlap for this Node and environment; no sessions were revoked.', 409);
                    }
                }
            }
            // A held pairing named by id stops serving now and owes its revoke, so a hold that still runs withdraws what it makes. An
            // unmatched answer proves nothing of a pairing whose hold ran at admission (issue 3540, [Held sessions](/reference/conn#held-sessions)).
            $holding = [];
            foreach ($pairings as $pairing) {
                if ($pairing->held && $pairing->revoked_at === null) {
                    $pairing->forceFill(['session' => null, 'withdrawn_at' => $pairing->withdrawn_at ?? Carbon::now()])->save();
                    if (ConnHeldSessions::holding($pairing->id)) {
                        $holding[] = $pairing->id;
                    }
                }
            }
            $reference = ['vault' => 'orbit-control-inputs', 'key' => 'conn-pairing-revoke-'.Str::uuid()];
            $operation = $this->operations->execute('conn.pairing_revoke', 'conn_pairing_node', $node->id, $gateway,
                ['conn_environment_id' => $environment->id, 'pairing_ids' => $pairings->pluck('id')->all(),
                    'pairing_links' => $pairings->pluck('pairing_link_id')->all(), 'holding_pairing_ids' => $holding,
                    'subject_node_id' => $node->id, 'caller_node_id' => $caller->id,
                    'intent' => ['url' => $environment->url, 'labels' => [],
                        // A withdrawn held pairing whose run failed has no link expiry; its window is unused, its label decides.
                        'pairings' => $pairings->filter(fn (ConnPairing $pairing): bool => $pairing->expires_at !== null || ($pairing->held && $pairing->withdrawn_at !== null))
                            ->map(fn (ConnPairing $pairing): array => [
                                'id' => $pairing->id, 'label' => $pairing->client_label, 'ipAddress' => $node->wireguard_ip,
                                'issuedAfter' => ($pairing->issue_started_at ?? $pairing->created_at)->toJSON(),
                                'expiresAt' => ($pairing->expires_at ?? $pairing->created_at)->toJSON(), 'delivered' => $pairing->credential === null,
                                ...$pairing->held ? ['held' => true] : [],
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
