<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\WebSocketRegistryOperation;

/** Records the task for one registry revision. Reverb's own reports close it; no Swamp run starts (R1). */
final readonly class StartWebSocketOperationAction
{
    public function __construct(private OperationStore $operations, private OperationIdempotency $idempotency, private NodeAccessAuthorizer $nodes) {}

    public function execute(WebSocketRegistryOperation $registry): Operation
    {
        $nodeId = $this->nodes->activeGatewayId() ?? throw new ResourceOperationException('websocket.gateway_missing', 'A registry change requires an active Gateway Node.', 409);
        $kind = $registry->kind === 'sync' ? 'websocket.sync' : 'websocket.app_'.$registry->kind;
        if (($existing = $this->idempotency->replay($kind)) !== null) {
            return $existing;
        }

        return $this->operations->create($kind, $registry->kind === 'sync' ? 'websocket_sync' : 'websocket_revision', $registry->revision, $nodeId,
            ['registry_operation_id' => $registry->id, 'epoch' => $registry->epoch, 'revision' => $registry->revision,
                'app' => $registry->result, 'caller_node_id' => $registry->actor_node_id], 'operator', null);
    }
}
