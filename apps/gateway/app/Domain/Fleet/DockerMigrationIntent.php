<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\Node;
use App\Models\Process;

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

        return ['mode' => $mode, 'migration' => ['fallback' => $fallback, 'recipe' => $recipe->identity,
            'snapshot' => $recipe->snapshot, 'docker_version' => $recipe->tools['docker.io']->version,
            'containerd_version' => $recipe->tools['containerd']->version, 'architecture' => NodeRecipeProgram::architecture($node),
            'process_ids' => array_map(strval(...), array_keys($specs)), 'process_states' => (object) $states, 'process_specs' => (object) $specs],
            'processes' => ['$secret' => 'fleet.docker_processes', 'id' => $node->id]];
    }
}
