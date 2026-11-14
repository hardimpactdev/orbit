<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\ProjectDefinitions\DefinitionEnvironment;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Process;
use App\Models\ProcessDefinition;

/**
 * Which change a container Instance's Process takes directly (docs/cli/process/destroy.mdx#a-process-in-an-instance-container).
 * The Project's development definitions and a deployment of the default change its Processes. `process:destroy` removes only a
 * Process that no current definition owns, and only with its own Instance named, so a wrong id never reaches another Instance.
 */
final readonly class ContainerProcessChange
{
    public function assertAdmissible(Process $process, string $verb, ?int $namedInstanceId): void
    {
        $instance = Instance::isMorphType($process->owner_type) ? Instance::query()->find($process->owner_id) : null;
        $definition = $instance === null || $process->source_definition_id === null ? null
            : ProcessDefinition::query()->where('project_id', $instance->project_id)->whereKey($process->source_definition_id)->first();
        $owned = $definition !== null && in_array(DefinitionEnvironment::Development->value, $definition->environments, strict: true);
        if ($verb !== 'destroy' || $owned) {
            throw new ResourceOperationException('operation.kind_unavailable', $this->throughDefinitions($process, $instance, $owned ? $definition : null), 503);
        }
        if ($namedInstanceId !== $instance?->id) {
            throw new ResourceOperationException('process.instance_mismatch', $namedInstanceId === null
                ? "Process [{$process->id}] runs in an Instance container: name its Instance with --instance."
                : "Process [{$process->id}] is not a Process of Instance [{$namedInstanceId}].", 422);
        }
    }

    private function throughDefinitions(Process $process, ?Instance $instance, ?ProcessDefinition $definition): string
    {
        $project = $instance === null ? '<project>' : (string) $instance->project_id;
        $default = $instance === null ? null : Instance::query()->where('project_id', $instance->project_id)->where('name', 'default')->first();
        $deploy = 'orbit instance:deploy '.($default === null ? '<default>' : (string) $default->id);
        if ($definition !== null) {
            return "Process [{$process->name}] comes from the Project's development definition [{$definition->name}]. "
                ."Remove the definition (orbit process:destroy {$definition->name} --project={$project} --yes), then deploy the default ({$deploy}).";
        }

        return "Change the Processes of a container Instance through the Project's development definitions "
            ."(orbit process:update NAME --project={$project}), then deploy the default ({$deploy}).";
    }
}
