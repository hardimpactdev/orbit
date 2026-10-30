<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmIncusProjection;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;

/** Accepts only a kit-verified, server-built family child intent. */
final readonly class AdmitTaskVmChildAction
{
    public function __construct(private StartOperationAction $operations) {}

    /** @param array<string,mixed> $intent */
    public function execute(Operation $parent, array $intent): Operation
    {
        $kind = ValidatedData::string($intent['kind']);
        $input = ValidatedData::object($intent['input']);
        $node = ValidatedData::integer($intent['node_id']);
        $definition = app(OperationKinds::class)->definition($parent->kind);
        $allowed = is_array($definition) ? ($definition['children'] ?? []) : [];
        if (! array_any($allowed, static fn (array $entry): bool => $entry['kind'] === $kind) || ! str_starts_with($kind, 'task_vm.')) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The child kind is not declared by this parent.', 422);
        }

        if (str_starts_with($kind, 'task_vm.incus_')) {
            $vm = TaskVm::query()->findOrFail($parent->target_id);

            return app(StartTaskVmIncusEffectAction::class)->execute($vm, substr($kind, strlen('task_vm.incus_')));
        }
        if (isset(TaskVmIncusProjection::ImageFields[$kind])) {
            $input = app(TaskVmIncusProjection::class)->admitImage($kind, $parent->target_id, $node, $input);
        }
        if ($kind === 'task_vm.prepare_runtime') {
            if (in_array($parent->kind, ['task_vm.provision', 'task_vm.enroll'], true)) {
                $enrolled = app(OperationChildren::class)->requireSucceeded($parent, 'enroll');
                if ($enrolled->kind !== 'task_vm.enroll_node') {
                    throw new ResourceOperationException('operation.completion_unproven', 'Runtime preparation requires the proven enrollment projection.', 409);
                }
            }

            return app(StartTaskVmRuntimeOperationAction::class)->execute($parent->target_id) ?? throw new ResourceOperationException('operation.execution_forbidden', 'The VM no longer admits runtime preparation.', 422);
        }
        if ($kind === 'task_vm.resume') {
            $vm = TaskVm::query()->findOrFail($parent->target_id);
            if ($vm->state === TaskVmState::Ready) {
                $power = app(TaskVmProvider::class)->observe($vm);
                if ($power !== null && $power->settled && ! $power->running) {
                    $vm->update(['state' => TaskVmState::Parked, 'stop_confirmed_at' => now()]);
                }
            }

            return app(StartTaskVmLifecycleOperationAction::class)->execute($parent->target_id, 'resume') ?? throw new ResourceOperationException('operation.execution_forbidden', 'The VM no longer admits a preservation resume.', 422);
        }
        if ($parent->target_type === 'task_vm_image' && ($input['phase'] ?? null) !== 'intent') {
            $build = TaskVmImageBuild::query()->findOrFail($parent->target_id);
            if ($build->attempt !== ($input['attempt'] ?? null) || $build->state !== TaskVmImageBuildState::Running || $build->cleanup_started_at !== null) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The image attempt no longer admits this phase.', 422);
            }
            $phase = ValidatedData::string($input['phase']);
            $step = match ($phase) {
                'promote' => TaskVmImageBuildStep::Promote,'prune' => TaskVmImageBuildStep::Prune,default => TaskVmImageBuildStep::Recipe
            };
            $program = $step === TaskVmImageBuildStep::Recipe ? 'base:'.$phase : null;
            $steps = $build->steps;
            $index = array_find_key($steps, static fn (array $entry): bool => $entry['step'] === $step->value && $entry['program'] === $program) ?? count($steps);
            $steps[$index] ??= ['step' => $step->value, 'program' => $program, 'started_at' => now()->toISOString(), 'seconds' => null, 'error' => null];
            $steps[$index]['started_at'] ??= now()->toISOString();
            $build->forceFill(['steps' => $steps, 'current_step' => $step, 'current_program' => $program])->save();
        }

        return $this->operations->execute($kind, $parent->target_type, $parent->target_id, $node, $input, 'task-vm-child', null);
    }
}
