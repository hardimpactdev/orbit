<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationTransportInput;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use SensitiveParameter;

/** Serializes the fresh stored-site check and publication with other development projections. */
final readonly class RouteWorkloadTransport implements OperationEffectTransport, OperationTransportInput
{
    public function __construct(private AgentCommandRunner $agents, private AgentEffectReceipts $receipts,
        private DevelopmentProjectionOperationLock $lock, private RouteWorkloadInput $inputs) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return AgentSchemas::inputSchema($run['name']);
    }

    public function wireInput(Operation $operation, array $run, #[SensitiveParameter] array $input): mixed
    {
        return AgentSchemas::wireInput($input);
    }

    public function run(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        return $this->lock->run(function () use ($operation, $run, $input): array {
            $operation->refresh();
            $instance = Instance::query()->findOrFail(ValidatedData::integer($operation->input['instance_id']));
            $route = Route::query()->findOrFail(ValidatedData::integer($operation->input['route_id']));
            if (! $route->sites_published) {
                return ['error_code' => 'route.plan_stale'];
            }
            $fresh = $this->inputs->forInstance($instance, $route, ValidatedData::integer($operation->node_id));
            $saved = ValidatedData::object($operation->state['route_workload_plan'] ?? []);
            unset($saved['packages']);
            if (json_decode(json_encode($fresh, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR) !== $saved) {
                return ['error_code' => 'route.plan_stale'];
            }

            return $this->agents->run(Node::query()->findOrFail($operation->node_id), $run['name'],
                $this->effectful($operation, $run) ? AgentEffectReceipts::input($run, $input) : $input);
        });
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return $run['name'] !== 'php.inspect-shared-pools';
    }

    public function receipt(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        return $this->receipts->read(Node::query()->findOrFail($operation->node_id), $run['name'], $run['effect_key']);
    }
}
