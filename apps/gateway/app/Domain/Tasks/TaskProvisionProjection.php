<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Processes\ProcessLifecycleInput;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmEnrollmentProjection;
use App\Domain\TaskVms\TaskVmProvisionIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\PendingSshHostKey;
use App\Jobs\TaskVms\EndCancelledTaskProvision;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** State projection only. Swamp owns source, setup, runtime and readiness. */
final readonly class TaskProvisionProjection implements DirectOperationProjection
{
    public function __construct(private TaskVmProvisionIntent $vms, private TaskVmEnrollmentProjection $enrollment) {}

    public function input(Operation $operation): array
    {
        $input = $this->vms->input($operation);
        $intent = ValidatedData::object($input['intent']);
        // The converge intent carries the recipe and lock files. The VM provider puts the whole intent into one argument, so the
        // intent leaves it and the workflow takes it as an input of its own.
        $converge = $intent['converge'] ?? null;
        unset($intent['converge']);
        $group = Task::topLevel()->findOrFail($operation->target_id);
        $guest = Instance::query()->findOrFail(ValidatedData::integer($operation->input['instance_id']))->node_id;
        // The guest's targets carry `host_keys: null`; the workflow binds the key its VM step pins.
        $members = [];
        $copies = ValidatedData::object($input['execution_secrets'] ?? []);
        app(PendingSshHostKey::class)->during($guest, function () use ($operation, &$members, &$copies): void {
            foreach (ProcessInstanceOperation::members($operation) as $member) {
                $native = app(ProcessLifecycleInput::class)->forInstanceMember($operation, $member);
                $members[] = ['id' => $member['id'], 'intent' => $native['intent']];
                if (isset($native['execution_secrets']['environment'])) {
                    $copies['member_'.$member['id']] = $native['execution_secrets']['environment'];
                }
            }
        });
        $route = is_array($operation->input['route'] ?? null) ? $operation->input['route'] : null;

        return [...$input, 'execution_secrets' => $copies, ...(is_array($converge) ? ['converge' => $converge] : []), 'intent' => [...$intent,
            'eligible' => ($intent['eligible'] ?? false) && $group->status === TaskGroupStatus::Reserved,
            'processes' => $members, 'route' => $route['route_create'] ?? null, 'route_secrets' => $route['execution_secrets'] ?? (object) []]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $result = $data['result'] = self::receipt(ValidatedData::object($data['result'] ?? []));
            if (in_array($data['status'], ['failed', 'interrupted'], true) && is_string($result['error_code'] ?? null)) {
                $data['error_code'] = $result['error_code'];
            }
            if ($data['status'] === 'succeeded') {
                $result = $data['result'] = [...$result, 'image' => TaskVm::query()->findOrFail(ValidatedData::integer($operation->input['task_vm_id']))->imageReceipt()];
            }
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, $result);
            }
            $projected = $this->record($operation, $data, $result, static fn (): Operation => app(DirectOperation::class)->project($operation, $data, $result));

            app(TaskMaintenanceContinuation::class)->apply($projected);

            return $projected;
        });
    }

    /**
     * Records the VM, the workspace Instance, its Route and Processes and the group from a provision receipt. `$record`
     * writes the Operation between the lookups and the writes. A kind that provisions as one step of a larger run
     * passes a closure that returns its own Operation and gets the same records; `$operation` then holds the
     * provision intent as its input.
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $result
     * @param  Closure(): Operation  $record
     */
    public function record(Operation $operation, array $data, array $result, Closure $record): Operation
    {
        $vm = $this->vms->owned($operation, lock: true);
        $group = Task::topLevel()->lockForUpdate()->findOrFail($operation->target_id);
        $instance = Instance::query()->where('task_workspace_owner_id', $group->id)
            ->where('node_id', $vm->node_id)->where('project_id', $group->project_id)->findOrFail(ValidatedData::integer($operation->input['instance_id']));
        $projected = $record();
        $this->recordRuntime($operation, $result, $data['error_code'] ?? null);
        $intent = ValidatedData::object($operation->input['enrollment']);
        if (in_array($data['status'], ['succeeded', 'failed', 'interrupted', 'cancelled'], true) && ($result['ready'] ?? false) === true) {
            $provider = ValidatedData::object($result['provider'] ?? []);
            if (($result['task_vm_id'] ?? null) !== $vm->id || ($result['guest_node_id'] ?? null) !== $vm->node_id
                || ($provider['image_fingerprint'] ?? null) !== $vm->image_fingerprint
                || ($provider['running'] ?? null) !== true || ($provider['settled'] ?? null) !== true) {
                throw new ResourceOperationException('operation.invalid_result', 'The Tasks result does not confirm its admitted VM and image.', 422);
            }
            $vm->update(Arr::only($provider, ['provider_id', 'address']));
            $this->enrollment->record($vm, $intent, ValidatedData::object($result['enrollment']));
            $vm->refresh();
        }
        if ($data['status'] === 'succeeded') {
            if (($result['ready'] ?? null) !== true) {
                throw new ResourceOperationException('operation.invalid_result', 'Tasks provision requires confirmed VM readiness.', 422);
            }
            if ($vm->state === TaskVmState::Parked) {
                // A claimed slot (docs/cli/tasks/vm-slots.mmd) runs again; its enrolment was recorded at its enrol.
                $vm->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'stop_confirmed_at' => null, 'error_code' => null, 'error_message' => null]);
            }
            $source = ValidatedData::object($result['source']);
            // A routed workspace keeps the Project facts its Route was published with, as instance:create does, so the Gateway's
            // later render matches the published site.
            $facts = $instance->task_workspace_routed ? [] : ['selected_php_version' => $source['php_version'], 'source_is_laravel' => $source['laravel']];
            $instance->update(['branch' => $source['branch'], 'starting_commit' => $source['commit'], ...$facts,
                'status' => InstanceState::SourceResolved, 'first_setup_pending' => false, 'failed_step' => null, 'error_code' => null]);
            $group->update(['status' => TaskGroupStatus::Running, 'started_at' => $group->started_at ?? now(),
                'reserved_at' => null, 'capacity_wait_reason' => null]);
        } elseif (in_array($data['status'], ['failed', 'interrupted'], true)) {
            if (in_array($vm->state, [TaskVmState::Provisioning, TaskVmState::Parked], true)) {
                $vm->update(['state' => TaskVmState::Failed, 'error_code' => $data['error_code'], 'error_message' => 'Native task provisioning failed.']);
            }
            $instance->update(['failed_step' => $result['failed_step'] ?? 'provision', 'error_code' => $data['error_code']]);
            $group->update(['status' => TaskGroupStatus::Failed, 'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
        } elseif ($data['status'] === 'cancelled') {
            $group->update(['status' => TaskGroupStatus::Cancelled, 'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
            // The run may have stopped while it created the VM or the workspace: the group's ending destroys what it started.
            EndCancelledTaskProvision::dispatch($group->id, $operation->id)->afterCommit();
        }

        return $projected;
    }

    /**
     * The workspace Route and Processes from the receipt's step outcomes, as instance.register records them.
     *
     * @param  array<string,mixed>  $result
     */
    private function recordRuntime(Operation $operation, array $result, mixed $errorCode): void
    {
        $routeId = $operation->input['route_id'] ?? null;
        if (is_int($routeId) && in_array($result['route'] ?? null, ['succeeded', 'failed'], true)) {
            $route = Route::query()->findOrFail($routeId);
            $route->update($result['route'] === 'succeeded'
                ? ['status' => RouteStatus::Active, 'sites_published' => true, 'failed_step' => null, 'error_code' => null]
                : ['failed_step' => 'native-create', 'error_code' => is_string($errorCode) ? $errorCode : 'tasks.provision_failed']);
        }
        $statuses = [];
        foreach (is_array($result['processes'] ?? null) ? $result['processes'] : [] as $entry) {
            if (is_string($entry) && str_contains($entry, '=')) {
                [$step, $status] = explode('=', $entry, 2);
                $statuses[$step] = $status;
            }
        }
        // Only a receipt that reports Process outcomes has members to complete.
        foreach ($statuses === [] ? [] : ProcessInstanceOperation::members($operation) as $index => $member) {
            $status = $statuses['Create, start Processes-'.$index] ?? null;
            if (! in_array($status, ['succeeded', 'failed'], true)) {
                continue;
            }
            $view = clone $operation;
            $view->input = [...$operation->input, 'members' => [$member]];
            app(ProcessInstanceOperation::class)->completeMembers($view, OperationStatus::from($status),
                $status === 'failed' ? (is_string($errorCode) ? $errorCode : 'tasks.provision_failed') : null, []);
        }
    }

    /**
     * The end check prints the whole receipt, workspace Route and Processes included. When it never ran, the
     * receipt comes from the workspace step, which always ends with it.
     *
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private static function receipt(array $result): array
    {
        $captured = $result['receipt'] ?? null;
        unset($result['receipt']);
        if ($result === [] && is_array($captured) && is_string($captured['result_json'] ?? null)) {
            return ValidatedData::object(json_decode($captured['result_json'], true, flags: JSON_THROW_ON_ERROR));
        }

        return $result;
    }
}
