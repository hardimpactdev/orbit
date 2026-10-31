<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\WebSocketRegistryOperation;

final readonly class StartWebSocketOperationAction
{
    public function __construct(private StartOperationAction $operations, private NodeAccessAuthorizer $nodes) {}

    public function execute(WebSocketRegistryOperation $registry): Operation
    {
        $nodeId = $this->nodes->activeGatewayId() ?? throw new ResourceOperationException('websocket.gateway_missing', 'A registry change requires an active Gateway Node.', 409);
        $kind = $registry->kind === 'sync' ? 'websocket.sync' : 'websocket.app_'.$registry->kind;

        $sync = $registry->kind === 'sync';

        return $this->operations->execute($kind, $sync ? 'websocket_sync' : 'websocket_revision', $registry->revision, $nodeId,
            ['registry_operation_id' => $registry->id, 'epoch' => $registry->epoch, 'revision' => $registry->revision,
                'app' => $registry->result, 'caller_node_id' => $registry->actor_node_id], 'operator', null);
    }
}
