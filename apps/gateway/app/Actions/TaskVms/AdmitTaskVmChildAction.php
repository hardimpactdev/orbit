<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\TaskVm;
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

        return $this->operations->execute($kind, $parent->target_type, $parent->target_id, $node, $input, 'task-vm-child', null);
    }
}
