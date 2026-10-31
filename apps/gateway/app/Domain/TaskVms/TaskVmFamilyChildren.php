<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\StartTaskVmIncusEffectAction;
use App\Actions\TaskVms\StartTaskVmRuntimeOperationAction;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\TaskVms\TaskVmInputs;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/**
 * @phpstan-type ChildIntent array{key:string,kind:string,node_id:int,input:array<string,mixed>}
 * Compiles exact child intent from admitted data and read-only observations. Swamp chooses the next key.
 */
final readonly class TaskVmFamilyChildren implements OperationFamilyChildren, OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        return ['descriptors' => []];
    }

    public function input(Operation $operation, array $run): array
    {
        return [];
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        $provider = ValidatedData::string($operation->input['provider'] ?? '');
        $required = match ($operation->kind) {
            'task_vm.provision' => ['lifecycle-intent' => 'task_vm.lifecycle_intent', 'provider-create' => 'task_vm.'.$provider.'_create', ...($provider === 'upcloud' ? ['provider-start' => 'task_vm.upcloud_start'] : []), 'prepare-enrollment' => 'task_vm.prepare_enrollment', 'enroll' => 'task_vm.enroll_node', 'runtime' => 'task_vm.prepare_runtime'],
            'task_vm.enroll' => ['lifecycle-intent' => 'task_vm.lifecycle_intent', 'prepare-enrollment' => 'task_vm.prepare_enrollment', 'enroll' => 'task_vm.enroll_node', 'runtime' => 'task_vm.prepare_runtime'],
            'task_vm.resume' => ['lifecycle-intent' => 'task_vm.lifecycle_intent', 'provider-start' => 'task_vm.'.$provider.'_start', 'runtime-confirmed' => 'task_vm.runtime_confirm'],
            'task_vm.destroy' => ['lifecycle-intent' => 'task_vm.lifecycle_intent', 'destroy-intent' => 'task_vm.mark_destroying', 'provider-delete' => 'task_vm.'.$provider.'_delete', 'forget-workspaces' => 'task_vm.forget_workspaces', 'forget-records' => 'task_vm.destroy_records', ...(($operation->input['key'] ?? null) === null ? [] : ['revoke-model-key' => 'task_vm.model_revoke'])],
            'task_vm.prepare_runtime' => ['model-key' => 'task_vm.model_issue', 'pi-configure' => 'task_vm.configure_runtime', 'pi-process-owner' => 'process.create', 'runtime-confirmed' => 'task_vm.runtime_confirm'],
            default => [],
        };
        if (in_array($operation->kind, ['task_vm.provision', 'task_vm.enroll'], true)
            && (ValidatedData::object($operation->state['enrollment'] ?? [])['enrolled'] ?? false) !== true) {
            $required['node-enrollment'] = 'node.add';
        }
        if ($operation->kind === 'task_vm.destroy' && ($operation->input['guest_node_id'] ?? null) !== null) {
            $required['node-removal'] = 'node.remove';
        }
        $proof = app(OperationChildren::class);
        if ($operation->kind === 'task_vm.destroy') {
            foreach (TaskVmInputs::workspaceRoutes($operation->input['workspace_routes'] ?? []) as $route) {
                $child = $proof->requireSucceeded($operation, 'workspace-route-'.$route['route_id']);
                if ($child->kind !== 'route.destroy' || $child->target_type !== 'route' || $child->target_id !== $route['route_id']) {
                    throw new ResourceOperationException('operation.completion_unproven', 'The workspace Route has no exact child proof.', 409);
                }
            }
        }
        foreach ($required as $key => $kind) {
            if ($proof->requireSucceeded($operation, $key)->kind !== $kind) {
                throw new ResourceOperationException('operation.completion_unproven', 'The required VM effect has a different kind.', 409);
            }
        }
        foreach ($operation->children()->get() as $child) {
            $key = (string) $child->child_key;
            if ($operation->kind === 'task_vm.destroy' && in_array($key, ['stop-worker', 'worker-power-off'], true)) {
                $proof->child($operation, $key);
            } else {
                $proof->requireSucceeded($operation, $key);
            }
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    /** @return array{intent:array<string,mixed>,children:list<ChildIntent>} */
    public function childContext(Operation $operation): array
    {
        $children = match ($operation->kind) {
            'task_vm.prepare_runtime' => $this->runtime($operation),
            default => $this->lifecycle($operation),
        };
        $definition = app(OperationKinds::class)->definition($operation->kind);
        if (! is_array($definition)) {
            throw new \LogicException('The VM kind has no Swamp contract.');
        }
        $declared = $definition['children'] ?? [];
        $allowed = array_column($declared, 'kind');
        $children = array_values(array_filter($children, static fn (array $child): bool => in_array($child['kind'], $allowed, true)));
        $vm = $operation->target_type === 'task_vm' ? TaskVm::query()->find($operation->target_id) : null;

        return ['intent' => ['guest_node_id' => $vm?->node_id, 'lifecycle_node_ids' => array_column($this->nodeIntents($operation), 'node_id'), 'workspace_route_node_ids' => array_column(TaskVmInputs::workspaceRoutes($operation->input['workspace_routes'] ?? []), 'node_id')], 'children' => $children];
    }

    /** @param array<string,mixed> $input
     * @return ChildIntent
     */
    private function child(string $key, string $kind, int $node, array $input): array
    {
        return ['key' => $key, 'kind' => $kind, 'node_id' => $node, 'input' => [...$input, 'effect_key' => $key]];
    }

    /** @return list<ChildIntent> */
    public function nodeIntents(Operation $operation): array
    {
        $stored = $operation->state['child_intents'] ?? [];
        if (! is_array($stored) || ! array_is_list($stored)) {
            throw new \LogicException('The VM Node intents must be a list.');
        }

        return array_map(static function (mixed $value): array {
            $intent = ValidatedData::object($value);

            return ['key' => ValidatedData::string($intent['key']), 'kind' => ValidatedData::string($intent['kind']),
                'node_id' => ValidatedData::integer($intent['node_id']), 'input' => ValidatedData::object($intent['input'])];
        }, $stored);
    }

    private function known(Operation $operation, string $key): bool
    {
        if (! Operation::query()->where('parent_operation_id', $operation->id)->where('child_key', $key)->where('status', 'succeeded')->exists()) {
            return false;
        }
        app(OperationChildren::class)->requireSucceeded($operation, $key);

        return true;
    }

    /** @return list<ChildIntent> */
    private function runtime(Operation $operation): array
    {
        $input = $operation->input;
        $node = ValidatedData::integer($input['node_id']);
        $vm = TaskVm::query()->findOrFail($operation->target_id);
        if ($vm->node_id !== $node || $node !== $operation->node_id) {
            throw new \LogicException('The runtime intent names another VM Node.');
        }

        return [
            $this->child('model-key', 'task_vm.model_issue', $node, ['task_vm_id' => $vm->id, 'origin' => $input['origin'], 'key' => $input['model_key']]),
            $this->child('pi-upload', 'task_vm.pi_upload', $node, Arr::only($input, ['artifact', 'sha256'])),
            $this->child('pi-install', 'task_vm.pi_install', $node, Arr::only($input, ['user', 'sha256'])),
            $this->child('pi-configure', 'task_vm.configure_runtime', $node, Arr::only($input, ['user', 'origin', 'models', 'model_key', 'token'])),
            ['key' => 'pi-process-owner', 'kind' => 'process.create', 'node_id' => $node, 'input' => ['process_id' => ValidatedData::integer($input['process_id'])]],
            $this->child('runtime-confirmed', 'task_vm.runtime_confirm', $node, ['task_vm_id' => $vm->id, 'sha256' => $input['sha256']]),
        ];
    }

    /** @return list<ChildIntent> */
    private function lifecycle(Operation $operation): array
    {
        $input = $operation->input;
        $vm = TaskVm::query()->findOrFail($operation->target_id);
        $node = ValidatedData::integer($input['execution_node_id']);
        $children = [];
        foreach (['provider-create' => 'create', 'provider-start' => 'start', 'provider-stop' => 'stop', 'provider-delete' => 'delete', 'worker-power-off' => 'stop'] as $key => $verb) {
            if ($vm->provider === TaskVmProviderKind::Incus) {
                $data = app(StartTaskVmIncusEffectAction::class)->snapshot($vm, $verb);
                $children[] = $this->child($key, 'task_vm.incus_'.$verb, (int) $vm->host_node_id, $data);
            } else {
                $children[] = $this->child($key, 'task_vm.upcloud_'.$verb, ValidatedData::integer($input['gateway_node_id']), ['task_vm_id' => $vm->id, 'token' => ['$secret' => 'upcloud.api', 'id' => $vm->id]]);
            }
        }
        if ($vm->provider === TaskVmProviderKind::UpCloud) {
            foreach (['provider-hard-stop', 'worker-power-off', 'delete-power-off'] as $key) {
                $children = array_values(array_filter($children, static fn (array $child): bool => $child['key'] !== $key));
                $children[] = $this->child($key, 'task_vm.upcloud_hard_stop', ValidatedData::integer($input['gateway_node_id']), ['task_vm_id' => $vm->id, 'token' => ['$secret' => 'upcloud.api', 'id' => $vm->id]]);
            }
        }
        foreach (['lifecycle-intent' => 'lifecycle_intent', 'prepare-enrollment' => 'prepare_enrollment', 'enroll' => 'enroll_node', 'destroy-intent' => 'mark_destroying', 'forget-workspaces' => 'forget_workspaces', 'forget-records' => 'destroy_records'] as $key => $verb) {
            $children[] = $this->child($key, 'task_vm.'.$verb, $node, ['task_vm_id' => $vm->id]);
        }
        if ($vm->node_id !== null) {
            $children[] = $this->child('stop-worker', 'task_vm.stop_worker', $vm->node_id, []);
            if ($vm->model_key !== null && $vm->ai_router_origin !== null) {
                $runtime = app(StartTaskVmRuntimeOperationAction::class)->snapshot($vm);
                $children[] = $this->child('runtime', 'task_vm.prepare_runtime', $vm->node_id, $runtime);
                $children[] = $this->child('runtime-confirmed', 'task_vm.runtime_confirm', $vm->node_id, ['task_vm_id' => $vm->id, 'sha256' => $runtime['sha256']]);
            }
        }
        foreach ($this->nodeIntents($operation) as $intent) {
            if (($intent['kind'] === 'node.add' && $this->known($operation, 'prepare-enrollment'))
                || ($intent['kind'] === 'node.remove' && $this->known($operation, 'forget-workspaces'))) {
                $children[] = $intent;
            }
        }
        $resume = [...$input, 'deadline_at' => min(ValidatedData::integer($input['deadline_at']), now()->addSeconds(540)->getTimestamp()), 'guest_node_id' => $vm->node_id];
        $children[] = $this->child('preservation-resume', 'task_vm.resume', $node, $resume);
        if ($input['key'] !== null) {
            $children[] = $this->child('revoke-model-key', 'task_vm.model_revoke', $node, ['task_vm_id' => $vm->id, 'origin' => $input['origin'], 'key' => $input['key']]);
        }

        foreach (TaskVmInputs::workspaceRoutes($input['workspace_routes'] ?? []) as $entry) {
            $route = ValidatedData::object($entry);
            $children[] = ['key' => 'workspace-route-'.ValidatedData::integer($route['route_id']), 'kind' => 'route.destroy', 'node_id' => ValidatedData::integer($route['node_id']), 'input' => ['route_id' => $route['route_id'], 'offline' => true]];
        }

        return $children;
    }
}
