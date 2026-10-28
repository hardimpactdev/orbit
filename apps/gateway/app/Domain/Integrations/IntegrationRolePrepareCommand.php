<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Actions\Analytics\StartAnalyticsOperationAction;
use App\Actions\Metrics\StartMetricsOperationAction;
use App\Actions\WebSocket\PrepareWebSocketRoleAction;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Keyed database-only preparation; Node work remains in the family workflow. */
final readonly class IntegrationRolePrepareCommand implements OperationGatewayCommand
{
    public function __construct(private IntegrationRoleIntent $roles) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => (object) []];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $intent = $this->roles->get($operation);
        if (isset($operation->state['integration_intent'])) {
            return ['prepared' => true];
        }
        $node = Node::query()->findOrFail($operation->node_id);
        $remove = str_ends_with($operation->kind, '.role_remove');
        $offline = $remove && ($operation->input['offline'] ?? false) === true;
        match ($intent['role']) {
            'metrics' => app(StartMetricsOperationAction::class)->execute($node, $intent['assignment_id'], $remove ? 'disable' : 'enable',
                purge: $remove && ($operation->input['purge_data'] ?? false) === true, unreachable: $offline, existing: $operation),
            'analytics' => app(StartAnalyticsOperationAction::class)->execute($node, $intent['assignment_id'], $remove ? 'disable' : 'enable', unreachable: $offline, existing: $operation),
            'websocket' => app(PrepareWebSocketRoleAction::class)->execute($operation, $intent),
            default => throw new ResourceOperationException('operation.execution_forbidden', 'The retained role is not an integration.', 422),
        };

        return ['prepared' => true];
    }
}
