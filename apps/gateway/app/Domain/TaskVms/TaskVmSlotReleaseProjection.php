<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeRouteRemovalCompletion;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Route;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the terminal run of a slot release. A run whose end check passed proves the VM and its seed clone gone and the
 * identity volume still there. The Gateway then scrubs its own records of the slot Node and makes the slot `free`. A run
 * that failed leaves the slot `destroying` with the error; no claim can take it and the host sweep releases it again.
 * See docs/cli/tasks/vm-slot-release.mmd.
 */
final readonly class TaskVmSlotReleaseProjection implements DirectOperationProjection, DirectOperationTimeout
{
    /** The end check: a native assert over the two host reads. */
    public const string EndCheck = 'Check the slot is clean';

    public function input(Operation $operation): array
    {
        $this->owned($operation);

        return TaskVmProviderWorkflowInput::envelope($operation->input);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        if ($data['status'] === OperationStatus::Interrupted->value) {
            $data['status'] = OperationStatus::Failed->value;
        }
        if ($data['status'] === OperationStatus::Failed->value) {
            $data['error_code'] = 'task_vm.slot_release_failed';
        }

        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($data['result'] ?? []));
            }
            $vm = $this->owned($operation, lock: true);
            if ($data['status'] === OperationStatus::Succeeded->value) {
                if (NativeRunResult::stepStatus(ValidatedData::object($data['result'] ?? []), self::EndCheck) !== 'succeeded') {
                    throw new ResourceOperationException('operation.result_invalid', 'A succeeded slot release needs its passed end check.', 422);
                }
                $leftovers = $this->scrub($vm);
                if ($leftovers === []) {
                    $this->free($vm);

                    return app(DirectOperation::class)->project($operation, $data, ['released' => true, 'task_vm_id' => $vm->id, 'name' => $vm->name]);
                }
                // The host is clean, but the Gateway still holds records of the task on the slot Node. The slot stays out of use.
                $code = 'task_vm.slot_not_clean';
                $vm->update(['state' => TaskVmState::Failed, 'error_code' => $code, 'error_message' => 'The slot Node still holds: '.implode(', ', $leftovers).'.']);
                report(new ResourceOperationException($code, "Slot [{$vm->name}] is not clean after its release: ".implode(', ', $leftovers).'.', 409));

                return app(DirectOperation::class)->project($operation, [...$data, 'status' => OperationStatus::Failed->value, 'error_code' => $code], ['error_code' => $code, 'leftovers' => $leftovers]);
            }
            if (in_array($data['status'], [OperationStatus::Failed->value, OperationStatus::Cancelled->value], true)) {
                $this->failed($vm, ValidatedData::nullableString($data['error_code'] ?? null));
            }

            return app(DirectOperation::class)->project($operation, $data, ($data['error_code'] ?? null) === null ? [] : ['error_code' => $data['error_code']]);
        });
    }

    public function timedOut(Operation $operation): void
    {
        $vm = TaskVm::query()->lockForUpdate()->find($operation->target_id);
        if ($vm instanceof TaskVm) {
            $this->failed($vm, 'operation.timed_out');
        }
    }

    /** The slot stays `destroying` with the error, so the host sweep admits its release again. */
    private function failed(TaskVm $vm, ?string $code): void
    {
        $vm->update(['error_code' => $code ?? 'task_vm.slot_release_failed', 'error_message' => 'The slot release did not complete; the host sweep tries it again.']);
    }

    private function owned(Operation $operation, bool $lock = false): TaskVm
    {
        $vm = TaskVm::query()->when($lock, static fn ($q) => $q->lockForUpdate())->findOrFail($operation->target_id);
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.slot_release' || $operation->target_type !== 'task_vm' || $vm->slot === null
            || ($input['task_vm_id'] ?? null) !== $vm->id || ($input['node_id'] ?? null) !== $operation->node_id || ($input['node_id'] ?? null) !== $vm->host_node_id
            || ($input['guest_node_id'] ?? null) !== $vm->node_id || ($input['name'] ?? null) !== $vm->name || ($input['group_id'] ?? null) !== $vm->group_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A slot release needs its admitted slot and host.', 422);
        }

        return $vm;
    }

    /**
     * Deletes the slot Node's observer facts and the task's workspace records, then lists what the Node still holds. The
     * next task uses the same workspace path, so an old checkout fact would read as current. The Node, its `pi-server`
     * Process and its role stay: they are the slot.
     *
     * @return list<string>
     */
    private function scrub(TaskVm $vm): array
    {
        $node = Node::query()->lockForUpdate()->findOrFail($vm->node_id);
        NodeFact::query()->where('node_id', $node->id)->delete();
        $routes = Route::query()->where(static fn ($query) => $query->where('node_id', $node->id)->orWhere('generation_basis_node_id', $node->id)
            ->orWhere('transition_node_id', $node->id)->orWhereHas('targets.instance', static fn ($q) => $q->where('node_id', $node->id)))->orderBy('id')->get();
        app(NativeRouteRemovalCompletion::class)->apply(['routes' => array_values($routes->map(static fn (Route $route): array => ['route_id' => $route->id, 'domain' => $route->domain,
            'replacement_of' => $route->isUnactivatedReplacement() ? $route->replaces_route_id : null])->all())]);
        $instances = Instance::query()->where('node_id', $node->id)->get();
        foreach ($instances as $instance) {
            $instance->schedules()->delete();
            $instance->processes()->get()->each(static fn (Process $process): bool => $process->delete() ?? false);
            $instance->delete();
        }
        $node->schedules()->delete();
        $node->hostedSchedules()->delete();
        $node->processes()->where('name', '!=', 'pi-server')->get()->each(static fn (Process $process): bool => $process->delete() ?? false);

        return $this->leftovers($node);
    }

    /** @return list<string> */
    private function leftovers(Node $node): array
    {
        $held = [
            'Instances' => Instance::query()->where('node_id', $node->id)->exists(),
            'Routes' => Route::query()->where('node_id', $node->id)->exists(),
            'Schedules' => $node->schedules()->exists() || $node->hostedSchedules()->exists(),
            'Processes' => $node->processes()->where('name', '!=', 'pi-server')->exists(),
            'database connections' => $node->databaseConnections()->exists(),
            'firewall rules' => $node->firewallRules()->exists(),
            'Tools' => $node->tools()->exists() || $node->toolManagers()->exists(),
        ];

        return array_keys(array_filter($held));
    }

    /** The slot keeps its Node, its hub peer, its identity volume, its tokens and its model key. Only the task leaves. */
    private function free(TaskVm $vm): void
    {
        $vm->update(['state' => TaskVmState::Free, 'group_id' => null, 'claimed_at' => null, 'ready_at' => null, 'warmed_at' => null, 'stop_confirmed_at' => null,
            'worker_stop_required' => false, 'image_fingerprint' => null, 'recipe_identity' => null, 'image_build_id' => null, 'image_fallback' => false,
            'publish_commit' => null, 'publish_branch' => null, 'publish_state' => null, 'publish_error' => null, 'publish_error_code' => null,
            'publish_queued_at' => null, 'publish_started_at' => null, 'error_code' => null, 'error_message' => null, ...TaskVm::ForgetTopology]);
    }
}
