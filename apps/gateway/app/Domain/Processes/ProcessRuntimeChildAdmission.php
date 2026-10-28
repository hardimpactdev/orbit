<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;

/** Installs or restarts an already recorded, Instance-owned Process. */
final readonly class ProcessRuntimeChildAdmission implements OperationChildAdmission
{
    public function __construct(private ProcessOperationAdmission $admission, private ProcessTargetResolver $targets) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $id = $intent['input']['process_id'] ?? null;
        $verb = match ($parent->kind) {
            'process.install_instance_definitions' => 'create',
            'process.restart_instance' => 'restart',
            default => null,
        };
        if (! is_int($id) || $verb === null || ! OperationChildIntent::same($intent['input'], ['process_id' => $id])
            || $intent['kind'] !== 'process.'.$verb || $parent->target_type !== 'instance' || $intent['node_id'] !== $parent->node_id) {
            $this->refuse();
        }
        $process = Process::query()->whereKey($id)->firstOrFail();
        if (! Instance::isMorphType($process->owner_type) || $process->owner_id !== $parent->target_id
            || $this->targets->forInspection($process)->node->id !== $parent->node_id) {
            $this->refuse();
        }
        if ($verb === 'restart' && ($process->status !== LifecycleStatus::Active || $process->desired_state !== DesiredProcessState::Running || ! $process->restart_on_deploy)) {
            $this->refuse();
        }
        if ($verb === 'create' && ($process->source_definition_id === null || in_array($process->status, [LifecycleStatus::Active, LifecycleStatus::Removing], true))) {
            $this->refuse();
        }

        return $this->admission->execute($process, $verb);
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.child_forbidden', 'This Process is not an eligible runtime child of the Instance.', 422);
    }
}
