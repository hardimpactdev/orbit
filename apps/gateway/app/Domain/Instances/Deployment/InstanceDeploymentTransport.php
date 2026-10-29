<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentMaterial;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationTransportInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\OperationAgentTransport;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Serializes each bounded named invocation with fresh smoke and deployment policy checks. */
final readonly class InstanceDeploymentTransport implements OperationEffectTransport, OperationTransportInput
{
    public function __construct(private InstanceDeploymentGuards $guards, private OperationAgentTransport $agent, private AgentCommandRunner $runner, private InstanceDeploymentBudget $budget) {}

    public function wireInput(Operation $operation, array $run, #[\SensitiveParameter] array $input): mixed
    {
        return $this->agent->wireInput($operation, $run, $input);
    }

    public function schema(Operation $operation, array $run): array|bool
    {
        return $this->agent->schema($operation, $run);
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->guards->around($operation, function (Instance $instance) use ($operation, $run, $input): array {
            $parent = $this->guards->deploymentParent($operation);
            $remaining = in_array($parent->kind, ['instance.deploy', 'instance.rollback'], true) ? $this->budget->requireRemaining($parent) : null;

            if ($run['name'] === 'tool.prune-vp-store') {
                return DB::transaction(function () use ($operation, $run, $input, $remaining): array {
                    DB::table('nodes')->where('id', $operation->node_id)->update(['id' => $operation->node_id]);
                    $references = app(InstanceVpEnvironmentMaterial::class)->references($operation->node_id ?? throw new \LogicException('Missing Node.'), ValidatedData::string($input['store']));
                    if ($references !== $input['references']) {
                        throw new ResourceOperationException('deployment.vp_references_changed', 'Runtime references changed before collection.', 409);
                    }

                    return $this->runner->run(Node::query()->findOrFail($operation->node_id), $run['name'], AgentEffectReceipts::input($run, $input), $remaining);
                });
            }

            return $this->runner->run(Node::query()->findOrFail($operation->node_id), $run['name'],
                $this->effectful($operation, $run) ? AgentEffectReceipts::input($run, $input) : $input, $remaining);
        });
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return $this->agent->effectful($operation, $run);
    }

    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->agent->receipt($operation, $run, $input);
    }
}
