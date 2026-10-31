<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Routes\RouteNodeEffectCommand;
use App\Domain\Routes\RouteOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Captures validated Route input; the native workflow owns execution. */
final readonly class StartRouteNodeOperationAction
{
    public function __construct(private StartOperationAction $operations, private RouteNodeEffectCommand $plans, private AgentDelivery $agents) {}

    /** @param array<string, mixed> $input */
    public function execute(string $kind, Node $node, array $input, ?Operation $parent = null): Operation
    {
        $fields = $kind === 'route.workload_verify' ? ['route_id', 'instance_id'] : ['route_id'];
        if (! in_array($kind, [...RouteOperationProjection::Kinds, ...RouteOperationProjection::LocalKinds], true) || $node->platform !== 'linux'
            || array_diff(array_keys($input), $fields) !== [] || array_diff($fields, array_keys($input)) !== []) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A stored Route effect on its Linux Node is required.', 422);
        }
        $draft = new Operation(['kind' => $kind, 'node_id' => $node->id, 'input' => $input]);
        $draft->parent_operation_id = $parent?->id;
        if ($kind === 'route.lan_remove') {
            $this->plans->prepare($draft);

            return $this->operations->execute($kind, 'route_effect', ValidatedData::integer($input['route_id']), $node->id,
                [...$input, 'lan_removal' => ['route_id' => $input['route_id'], 'agent_sha256' => $this->agents->pin($node)]],
                $parent === null ? 'operator' : $parent->triggered_by, $parent?->request_id);
        }
        $plan = $this->plans->networkVerification($draft);
        $protocol = match ($plan['command']) {
            'route.verify-certificate' => 'tls',
            'route.verify-http' => 'http',
            default => throw new \LogicException('Route verification needs a fixed probe.'),
        };

        return $this->operations->execute($kind, 'route_effect', ValidatedData::integer($input['route_id']), $node->id,
            [...$input, 'verification' => ['protocol' => $protocol, 'probe' => $plan['input'], 'agent_sha256' => $this->agents->pin($node)]],
            $parent === null ? 'operator' : $parent->triggered_by, $parent?->request_id);
    }
}
