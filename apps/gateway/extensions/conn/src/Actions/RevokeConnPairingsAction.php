<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Integrations\IntegrationAvailability;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

final readonly class RevokeConnPairingsAction
{
    public function __construct(private StartOperationAction $operations, private NodeAccessAuthorizer $nodes) {}

    public function execute(Node $node, ConnEnvironment $environment, Node $caller): Operation
    {
        IntegrationAvailability::assertKind('conn.pairing_revoke');
        $gateway = $this->nodes->activeGatewayId() ?? throw new ResourceOperationException('conn.gateway_missing', 'Pairing needs one active Gateway Node.', 409);
        $ids = ConnPairing::query()->where('node_id', $node->id)->where('conn_environment_id', $environment->id)
            ->whereNull('revoked_at')->orderBy('id')->pluck('id')->all();

        return $this->operations->execute('conn.pairing_revoke', 'conn_pairing_node', $node->id, $gateway,
            ['conn_environment_id' => $environment->id, 'environment' => ['$secret' => 'conn.environment', 'id' => $environment->id],
                'pairing_ids' => $ids, 'subject_node_id' => $node->id, 'caller_node_id' => $caller->id], 'operator', null);
    }
}
