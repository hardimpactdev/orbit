<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;

/** Captures public hub intent; the native209 workflow performs every host effect. */
final readonly class TaskVmHubFilter
{
    public function __construct(private StartOperationAction $operations, private TaskVmHubPolicy $policy) {}

    /** @param array{range:string,dns:string,gateway:string,pi_port:int,reverb:string,model:string,routers:list<string>} $input */
    public function prepare(Node $node, array $input): Operation
    {
        $rule = $this->policy->controlRule() ?? throw new TaskVmException('task_vm.fleet_unavailable', 'The active209 SSH control Node is required.');
        $intent = ['policy' => $input, 'node_id' => $node->id, 'ssh' => app(SwampSshExecutionInput::class)->forNode($node),
            'source' => $rule->shape->source, 'range' => $input['range'], 'nft' => $this->policy->render($input, $rule),
            'unit' => TaskVmHubPolicy::Unit, 'control_rule' => array_slice($rule->arguments, 1),
            'router_rules' => array_map(static fn (UfwManagedRule $router): array => array_slice($router->arguments, 1), $this->policy->routerRules($input['routers'], $input['range']))];

        return $this->operations->execute('task_vm.hub_converge', 'node_firewall', $node->id, $node->id, ['intent' => $intent], 'operator', null);
    }
}
