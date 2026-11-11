<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Processes\ProcessRemovalGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\InstanceNodeServiceTarget;
use App\Models\Process;
use SensitiveParameter;

/** An attached service keeps its managed Process until every dependent Instance releases it. */
final readonly class InstanceNodeServiceProcessRemovalGuard implements ProcessRemovalGuard
{
    public function assertRemovable(#[SensitiveParameter] Process $process): void
    {
        $instances = $this->dependentInstances($process);
        if ($instances !== []) {
            throw new ResourceOperationException(
                errorCode: 'process.required_by_instance_node_service',
                message: "Process [{$process->name}] is attached to ".implode(', ', $instances).'. Detach those Node service targets before destroying the Process.',
                status: 409,
            );
        }
    }

    public function assertClaimable(#[SensitiveParameter] Process $process): void
    {
        $instances = $this->dependentInstances($process);
        if ($instances !== []) {
            throw new ResourceOperationException(
                errorCode: 'process.node_service_ownership_conflict',
                message: "Process [{$process->name}] is attached to ".implode(', ', $instances).'. Detach those Node service targets before claiming ownership of the Process.',
                status: 409,
            );
        }
    }

    /** @return list<string> */
    private function dependentInstances(Process $process): array
    {
        $instances = [];
        foreach (InstanceNodeServiceTarget::query()->where('process_id', $process->id)->with('instance.project')->orderBy('instance_id')->get() as $target) {
            $instance = $target->instance;
            $instances[$instance->id] = "Instance [{$instance->project->slug}/{$instance->name}] (id {$instance->id})";
        }

        return array_values($instances);
    }
}
