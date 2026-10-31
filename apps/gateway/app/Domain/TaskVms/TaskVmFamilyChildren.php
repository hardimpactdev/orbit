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
use App\Infrastructure\TaskVms\TaskVmUpCloudImageRead;
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
            'task_vm.park' => ['lifecycle-intent' => 'task_vm.lifecycle_intent', 'provider-stop' => 'task_vm.'.$provider.'_stop', 'confirm-stopped' => 'task_vm.confirm_stopped'],
            'task_vm.destroy' => ['lifecycle-intent' => 'task_vm.lifecycle_intent', 'destroy-intent' => 'task_vm.mark_destroying', 'provider-delete' => 'task_vm.'.$provider.'_delete', 'forget-workspaces' => 'task_vm.forget_workspaces', 'forget-records' => 'task_vm.destroy_records', ...(($operation->input['key'] ?? null) === null ? [] : ['revoke-model-key' => 'task_vm.model_revoke'])],
            'task_vm.prepare_runtime' => ['model-key' => 'task_vm.model_issue', 'pi-configure' => 'task_vm.configure_runtime', 'pi-process-owner' => 'process.create', 'runtime-confirmed' => 'task_vm.runtime_confirm'],
            'task_vm.upcloud_image_build' => ['delete-smoke' => 'task_vm.upcloud_image_delete_smoke'],
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
            'task_vm.upcloud_image_build' => $this->upcloudImage($operation),
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

    /** @return array<string,mixed> */
    private function recipe(string $json): array
    {
        $value = json_decode($json, flags: JSON_THROW_ON_ERROR);
        if (! $value instanceof \stdClass) {
            throw new \LogicException('The recipe input must be an object.');
        }

        return get_object_vars($value);
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

    /** @return list<string> */
    private function bootstrapKeys(Operation $operation, string $base): array
    {
        $failed = Operation::query()->where('parent_operation_id', $operation->id)->where('status', 'failed')->where('error_code', 'agent.supervisor_not_ready')->pluck('child_key')->all();
        $keys = [$base];
        for ($attempt = 0; in_array($keys[$attempt], $failed, true); $attempt++) {
            $keys[] = $base.'-retry-'.($attempt + 1);
        }

        return $keys;
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
        foreach (['lifecycle-intent' => 'lifecycle_intent', 'prepare-enrollment' => 'prepare_enrollment', 'enroll' => 'enroll_node', 'confirm-stopped' => 'confirm_stopped', 'destroy-intent' => 'mark_destroying', 'forget-workspaces' => 'forget_workspaces', 'forget-records' => 'destroy_records'] as $key => $verb) {
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

    /** @return list<ChildIntent> */
    private function upcloudImage(Operation $operation): array
    {
        $input = $operation->input;
        $id = $operation->target_id;
        $node = (int) $operation->node_id;
        $children = [];
        $provider = static fn (string $verb, array $extra = []): array => ['build_id' => $id, ...($verb === 'prepare' ? [] : ['token' => ['$secret' => 'upcloud.api', 'id' => $id]]), ...$extra];
        foreach (['prepare', 'launch-builder', 'launch-smoke', 'stop', 'hard-stop-builder', 'hard-stop-smoke', 'templatize', 'delete-builder', 'delete-smoke', 'promote', 'discard-template'] as $verb) {
            $extra = str_starts_with($verb, 'launch-') ? ['bootstrap' => ['$secret' => 'task_vm.image_bootstrap', 'id' => $id]] : [];
            $key = $verb === 'stop' ? 'stop-builder' : $verb;
            $children[] = $this->child($key, 'task_vm.upcloud_image_'.str_replace('-', '_', $verb), $node, $provider($verb, $extra));
            if (in_array($verb, ['delete-builder', 'delete-smoke', 'hard-stop-builder', 'hard-stop-smoke', 'discard-template'], true)) {
                $children[] = $this->child('cleanup-'.$verb, 'task_vm.upcloud_image_'.str_replace('-', '_', $verb), $node, $provider($verb));
            }
        }
        $guest = fn (string $key, string $command, array $parameters = [], string $slot = 'builder'): array => $this->child($key, 'task_vm.upcloud_guest_'.str_replace(['.', '-'], '_', $command), $node, ['build_id' => $id, 'slot' => $slot, 'parameters_json' => json_encode((object) $parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        $recipe = $this->recipe(ValidatedData::string($input['recipe']));
        foreach (['builder', 'smoke'] as $slot) {
            foreach ($this->bootstrapKeys($operation, $slot.'-agent') as $key) {
                $children[] = $guest($key, 'agent.install-guest', [], $slot);
            }
            $children[] = $guest($slot.'-boot-confirmed', 'guest.cloud-init', [], $slot);
        }
        foreach ([['owner', 'image.owner', []], ['recipe-marker', 'image.recipe-marker', ['identity' => $input['identity'], 'profile' => 'base']], ['verify', 'image.verify', $recipe], ['boot-policy', 'image.boot', ['lean' => false]], ['firewall', 'image.firewall', []], ['clean', 'image.clean', []]] as [$key,$command,$parameters]) {
            $children[] = $guest($key, $command, $parameters);
        }
        $children[] = $guest('smoke-verify', 'image.verify', $recipe, 'smoke');
        $children[] = $guest('smoke-check', 'image.check', ['lean_boot' => false, 'firewall' => true], 'smoke');
        if ($this->known($operation, 'builder-boot-confirmed') && ! $this->known($operation, 'clean')) {
            foreach (['apt-source', 'packages', 'javascript', 'rust', 'agent', 'pi'] as $phase) {
                if ($phase === 'pi' && $input['pi_artifact'] === null) {
                    continue;
                }
                $state = app(TaskVmUpCloudImageRead::class)->read($operation, 'phase-'.$phase);
                if (($state['state'] ?? null) === 'complete') {
                    continue;
                }
                if (isset($state['boot_id'])) {
                    $children[] = $guest($phase.'-start', 'image.start-phase', ['key' => ['identity' => $input['identity'], 'profile' => 'base', 'phase' => $phase, 'execution' => 'upcloud-'.$id], 'boot_id' => $state['boot_id'], 'recipe' => $recipe]);
                }
                if ($phase === 'pi') {
                    $artifact = ValidatedData::object($input['pi_artifact']);
                    $children[] = $guest('pi-artifact', 'pi.upload-guest', ['artifact' => $artifact['path'], 'sha256' => $artifact['sha256']]);
                }
                break;
            }
        }
        if ($this->known($operation, 'promote')) {
            $state = app(TaskVmUpCloudImageRead::class)->read($operation, null);
            foreach (ValidatedData::stringList($state['prune_templates']) as $uuid) {
                $children[] = $this->child('prune-'.$uuid, 'task_vm.upcloud_image_prune_template', $node, $provider('prune-template', ['template_uuid' => $uuid]));
            }
        }

        return $children;
    }
}
