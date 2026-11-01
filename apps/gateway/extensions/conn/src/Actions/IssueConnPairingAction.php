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

final readonly class IssueConnPairingAction
{
    public function __construct(private StartOperationAction $operations, private NodeAccessAuthorizer $nodes) {}

    public function admit(Node $node, ConnEnvironment $environment): Operation
    {
        IntegrationAvailability::assertKind('conn.pairing_create');
        $this->assertSession($environment);
        $gateway = $this->nodes->activeGatewayId() ?? throw new ResourceOperationException('conn.gateway_missing', 'Pairing needs one active Gateway Node.', 409);

        return DB::transaction(function () use ($node, $environment, $gateway): Operation {
            $pairing = ConnPairing::query()->create(['node_id' => $node->id, 'conn_environment_id' => $environment->id,
                'client_label' => $node->name.' via Orbit '.Str::lower(Str::random(8))]);

            $reference = ['vault' => 'orbit-control-inputs', 'key' => 'conn-pairing-create-'.Str::uuid()];
            $operation = $this->operations->execute('conn.pairing_create', 'conn_pairing', $pairing->id, $gateway,
                ['conn_environment_id' => $environment->id, 'intent' => ['url' => $environment->url, 'pairing_id' => $pairing->id, 'label' => $pairing->client_label],
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
        if (! $environment->admin_session_expires_at->isFuture()) {
            throw new ResourceOperationException('conn.session_expired', "The Gateway's admin session on {$environment->label} expired. Restart that T3 server so it registers again.", 409);
        }
    }
}
