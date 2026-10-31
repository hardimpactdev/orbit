<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** The existing management key, delivered only to the configured collector Node. */
final readonly class AiRouterManagementMaterial implements OperationSecretSource
{
    public function __construct(private AiRouterState $state, private AiRouterPlacement $placement, private ExtensionStore $extensions) {}

    public function name(): string
    {
        return 'ai_router.management';
    }

    public function targetTypes(): array
    {
        return ['ai_router'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'ai_router.account_update' && $operation->target_type === 'ai_router'
            && $operation->target_id === $operation->node_id ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($id !== $this->ownerId($operation) || $key !== '' || $parameter !== 'router'
            || $operation->status->terminal() || ! is_string($operation->state['swamp_run_id'] ?? null)
            || ! $this->extensions->enabled('ai-router') || ! $this->state->enabled()
            || $this->state->nodeId() !== $id || rtrim((string) $this->state->routerUrl(), '/') !== ($operation->input['origin'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The management material belongs to its admitted ai-router root.', 422);
        }
        $this->placement->node($id);
        $managementKey = $this->state->managementKey();
        if (! is_string($managementKey) || $managementKey === '') {
            throw new ResourceOperationException('ai_router.disabled', 'The recorded CLIProxyAPI management key is unavailable.', 409);
        }

        return new OperationSecretValue(['management_key' => $managementKey], [$managementKey]);
    }
}
