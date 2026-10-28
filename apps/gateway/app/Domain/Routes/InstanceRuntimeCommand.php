<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Instance;
use App\Models\Operation;

/** Records runtime removal input or checks its evidence. Neither operation executes on a Node. */
final readonly class InstanceRuntimeCommand implements OperationGatewayCommand
{
    public function __construct(private SharedPhpPoolInput $php, private NodeCaddyfileRenderer $caddy, private ManagedUserAccountResolver $accounts) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['instance_id', 'action'],
            'properties' => ['instance_id' => ['type' => 'integer', 'minimum' => 1], 'action' => ['enum' => ['prepare', 'prove']]]];
    }

    public function run(Operation $operation, array $input): array
    {
        if (! in_array($operation->kind, ['route.withdraw_instance', 'route.cleanup_instance'], true) || $input['instance_id'] !== $operation->input['instance_id']) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This runtime boundary was not admitted.', 422);
        }
        $instance = Instance::query()->with('node')->findOrFail($input['instance_id']);
        if ($instance->node_id !== $operation->node_id || $instance->status !== InstanceState::Removing) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The removing Instance must belong to the Operation Node.', 422);
        }
        $operation->refresh();
        if ($input['action'] === 'prove') {
            foreach (app(InstanceRuntimeExecution::class)->context($operation)['descriptors'] as $descriptor) {
                if ($descriptor['key'] === 'instance-runtime-proof') {
                    continue;
                }
                $run = $operation->state['executor_runs'][$descriptor['key']] ?? null;
                if (! is_array($run['result'] ?? null) || isset($run['result']['error_code']) || $run['name'] !== $descriptor['name'] || $run['transport'] !== $descriptor['transport']) {
                    throw new ResourceOperationException('operation.completion_unproven', 'Every runtime withdrawal effect needs a successful receipt.', 409);
                }
            }

            return ['ready' => true];
        }
        if (is_array($operation->state['instance_runtime_plan'] ?? null)) {
            return ['prepared' => true];
        }
        $cleanup = $operation->kind === 'route.cleanup_instance';
        $plan = ['production' => $instance->placedOnAppProd()];
        if (! $plan['production']) {
            $plan['php'] = $this->php->forNode($instance->node);
        } elseif ($cleanup && ! ProductionPhpRuntimeIdentity::isAbsent($instance)) {
            $identity = ProductionPhpRuntimeIdentity::from($instance);
            $plan['production_php'] = ['user' => $identity->user, 'version' => $identity->version, 'marker' => $identity->marker()];
        }
        if ($cleanup) {
            $render = $this->caddy->render($instance->node);
            if (! $render->buildable()) {
                return ['error_code' => 'caddy.render_failed'];
            }
            $plan['caddy'] = ['content' => $render->content, 'version' => $render->version, 'listen_addresses' => $render->listenAddresses,
                'unserved' => $render->sites === [] || ! CaddySiteRoles::nodeExpectsCaddy($instance->node_id)];
            $plan['certificate'] = ['scope' => "app-instance-{$instance->id}", 'home' => $this->accounts->resolve($instance->node)->home];
        }
        $operation->update(['state' => [...($operation->state ?? []), 'instance_runtime_plan' => $plan]]);

        return ['prepared' => true];
    }
}
