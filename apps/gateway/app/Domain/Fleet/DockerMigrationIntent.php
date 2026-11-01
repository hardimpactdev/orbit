<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Processes\ProcessLifecycleInput;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;

/** Snapshot owning Process identities without exposing their commands or environment. */
final readonly class DockerMigrationIntent
{
    public function __construct(private ManagedNodeEligibility $eligibility, private ProcessTargetResolver $targets, private DockerProcessRenderer $docker) {}

    /** @return array<string,mixed> */
    public function input(Node $node, MachineRecipe $recipe, string $mode, bool $fallback): array
    {
        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.docker_migration_unsupported', 'Docker migration requires an active managed Linux Node.', 422);
        }
        $states = $specs = [];
        foreach (Process::query()->with('owner')->where('runtime', ProcessRuntime::Docker->value)->get() as $process) {
            if (($process->owner instanceof Node ? $process->owner->id : $process->owner->node_id) !== $node->id) {
                continue;
            }
            $target = $this->targets->forInstallation($process);
            if ($target->node->id !== $node->id) {
                throw new ResourceOperationException('node.docker_migration_changed', 'The Process target changed.', 409);
            }
            $states[(string) $process->id] = $process->desired_state->value;
            $specs[(string) $process->id] = ['name' => $this->docker->containerName($process), 'hash' => $this->docker->specHash($process, $target), 'volumes' => $process->runtime_config['volumes'] ?? []];
        }

        return ['ssh_execution' => app(SwampSshExecutionInput::class)->forNode($node), 'mode' => $mode, 'migration' => ['fallback' => $fallback, 'recipe' => $recipe->identity,
            'snapshot' => $recipe->snapshot, 'docker_version' => $recipe->tools['docker.io']->version,
            'containerd_version' => $recipe->tools['containerd']->version, 'architecture' => NodeRecipeProgram::architecture($node),
            'process_ids' => array_map(strval(...), array_keys($specs)), 'process_states' => (object) $states, 'process_specs' => (object) $specs]];
    }

    /** @return array{processes:list<array<string,mixed>>,execution_secrets:array<string,mixed>} */
    public function nativeInput(Operation $operation): array
    {
        if ($operation->kind !== 'fleet.docker_migrate' || $operation->target_type !== 'node' || $operation->target_id !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Docker definitions do not belong to this Node.', 422);
        }
        $definitions = [];
        $migration = ValidatedData::object($operation->input['migration']);
        $specs = (array) $migration['process_specs'];
        $states = (array) $migration['process_states'];
        foreach (ValidatedData::stringList($migration['process_ids']) as $processId) {
            $process = Process::query()->findOrFail((int) $processId);
            $target = $this->targets->forInstallation($process);
            $spec = (array) $specs[$processId];
            if ($target->node->id !== $operation->node_id || $process->runtime !== ProcessRuntime::Docker
                || $this->docker->containerName($process) !== $spec['name']
                || $process->desired_state->value !== $states[$processId]
                || ! hash_equals(ValidatedData::string($spec['hash']), $this->docker->specHash($process, $target))) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Docker definition changed after admission.', 422);
            }
            $input = app(ProcessOperationAdmission::class)->inputFor($process, 'create', target: $target);
            $definitions[] = ['id' => $process->id, 'input' => $input,
                'material' => ['definition' => $this->docker->containerInput($process, $target)]];
        }
        $members = $copies = [];
        foreach ($definitions as $definition) {
            $native = app(ProcessLifecycleInput::class)->forRenderedInstallation($definition['input'], $definition['material'],
                Node::query()->findOrFail($operation->node_id),
                'process-parent-operation-'.$operation->id.'-node-'.$operation->node_id.'-member-'.$definition['id']);
            $members[] = ['id' => $definition['id'], 'intent' => [...$native['intent'], 'ssh_execution' => $operation->input['ssh_execution']]];
            if (isset($native['execution_secrets']['environment'])) {
                $copies['member_'.$definition['id']] = $native['execution_secrets']['environment'];
            }
        }

        return ['processes' => $members, 'execution_secrets' => $copies];
    }
}
