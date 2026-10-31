<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Actions\Operations\StartOperationAction;
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

final readonly class RevokeConnPairingsAction
{
    public function __construct(private StartOperationAction $operations, private NodeAccessAuthorizer $nodes) {}

    public function execute(Node $node, ConnEnvironment $environment, Node $caller): Operation
    {
        IntegrationAvailability::assertKind('conn.pairing_revoke');
        $gateway = $this->nodes->activeGatewayId() ?? throw new ResourceOperationException('conn.gateway_missing', 'Pairing needs one active Gateway Node.', 409);

        return DB::transaction(function () use ($node, $environment, $caller, $gateway): Operation {
            $environment = ConnEnvironment::query()->lockForUpdate()->findOrFail($environment->id);
            $pairings = ConnPairing::query()->where('node_id', $node->id)->where('conn_environment_id', $environment->id)
                ->whereNull('revoked_at')->orderBy('id')->get();
            $reference = ['vault' => 'orbit-control-inputs', 'key' => 'conn-pairing-revoke-'.Str::uuid()];
            $operation = $this->operations->execute('conn.pairing_revoke', 'conn_pairing_node', $node->id, $gateway,
                ['conn_environment_id' => $environment->id, 'pairing_ids' => $pairings->pluck('id')->all(),
                    'subject_node_id' => $node->id, 'caller_node_id' => $caller->id,
                    'intent' => ['url' => $environment->url, 'labels' => $pairings->pluck('client_label')->all(),
                        'links' => $pairings->pluck('pairing_link_id')->filter()->values()->all()],
                    'execution_secrets' => ['admin_session' => $reference]], 'operator', null);
            if ($operation->wasRecentlyCreated) {
                app(SwampCommandStep::class)->executionCopy($reference['vault'], $reference['key'], $environment->admin_session);
            }

            return $operation;
        });
    }
}
