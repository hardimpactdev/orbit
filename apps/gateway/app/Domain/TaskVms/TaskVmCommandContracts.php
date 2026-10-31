<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Support\ValidatedData;
use LogicException;

/** Family-owned command declarations. Swamp owns execution. */
final class TaskVmCommandContracts
{
    /** @return array<string, mixed> */
    public static function agentInput(string $command): array
    {
        $input = AgentSchemas::inputSchema($command);
        if (! is_array($input)) {
            throw new LogicException("The agent schema for [{$command}] is missing.");
        }

        return ValidatedData::object($input);
    }

    /** @return array<string, array<string, mixed>> */
    public static function runtimeKinds(): array
    {
        $kinds = [];
        foreach ([
            'model_issue' => ['issue', ['task_vm_id', 'origin', 'key']],
            'model_revoke' => ['revoke', ['task_vm_id', 'origin', 'key']],
            'pi_upload' => ['upload', ['artifact', 'sha256']],
            'runtime_confirm' => ['confirm', ['task_vm_id', 'sha256']],
        ] as $verb => [$action, $fields]) {
            $properties = ['action' => ['const' => $action]];
            foreach ($fields as $field) {
                $properties[$field] = $field === 'task_vm_id' ? ['type' => 'integer', 'minimum' => 1] : ['type' => 'string'];
            }
            $run = ['transport' => 'task_vm_runtime', 'name' => 'task_vm.runtime', 'input_fields' => $fields,
                'input_values' => ['action' => $action], 'input_schema' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => array_keys($properties), 'properties' => $properties]];
            if (in_array($action, ['issue', 'revoke'], true)) {
                $run['secrets'] = ['key' => 'task_vm.model_key'];
            }
            $kinds['task_vm.'.$verb] = ['executor' => 'swamp', 'completion' => TaskVmEffectCompletion::class, 'runs' => [$run],
                'completion_contract' => ['step' => str_replace('_', '-', $verb), 'transport' => 'task_vm_runtime', 'name' => 'task_vm.runtime', 'result' => ['ready' => true]],
                'result_fields' => ['ready', 'process_id', 'error_code']];
        }
        foreach (['pi_install' => ['pi.install-artifact', ['user', 'sha256']], 'configure_runtime' => ['pi.configure-runtime', ['user', 'origin', 'models', 'model_key', 'token']]] as $verb => [$name, $fields]) {
            $run = ['transport' => 'agent', 'name' => $name, 'input_fields' => $fields, 'input_values' => []];
            if ($verb === 'configure_runtime') {
                $run['secrets'] = ['model_key' => 'task_vm.model_key', 'token' => 'task_vm.pi_token'];
            }
            $kinds['task_vm.'.$verb] = ['executor' => 'swamp', 'completion' => TaskVmEffectCompletion::class, 'execution' => TaskVmAgentExecution::class, 'runs' => [$run],
                'completion_contract' => ['step' => str_replace('_', '-', $verb), 'transport' => 'agent', 'name' => $name, 'result' => []],
                'result_fields' => ['changed', 'error_code']];
        }
        $children = array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input.node_id'], array_keys($kinds));
        $children[] = ['kind' => 'process.create', 'node_from' => 'input.node_id'];
        $kinds['task_vm.prepare_runtime'] = [
            'executor' => 'swamp', 'completion' => TaskVmRuntimeCompletion::class, 'runs' => [], 'children' => $children,
            'completion_contract' => ['children_succeeded' => ['task_vm.model_issue', 'task_vm.configure_runtime', 'process.create', 'task_vm.runtime_confirm']],
            'result_fields' => ['ready', 'error_code'],
        ];

        return $kinds;
    }

    /** @return array<string, array<string, mixed>> */
    public static function lifecycleKinds(): array
    {
        $kinds = ['task_vm.lifecycle_intent' => ['executor' => 'swamp', 'result_fields' => ['ready']]];
        $schema = static fn (string $action): array => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['action', 'task_vm_id'], 'properties' => ['action' => ['const' => $action], 'task_vm_id' => ['type' => 'integer', 'minimum' => 1]]];
        foreach (['prepare_enrollment' => 'prepare-enrollment', 'enroll_node' => 'enroll', 'mark_destroying' => 'destroying', 'forget_workspaces' => 'forget-workspaces', 'destroy_records' => 'forget'] as $verb => $action) {
            $kinds['task_vm.'.$verb] = ['executor' => 'swamp', 'completion' => TaskVmEffectCompletion::class,
                'runs' => [['transport' => 'task_vm_lifecycle', 'name' => 'task_vm.lifecycle', 'input_fields' => ['task_vm_id'],
                    'input_values' => ['action' => $action], 'input_schema' => $schema($action)]],
                'completion_contract' => ['step' => str_replace('_', '-', $verb), 'transport' => 'task_vm_lifecycle', 'name' => 'task_vm.lifecycle', 'result' => ['ready' => true]],
                'result_fields' => ['ready', 'node_id', 'enrolled', 'error_code']];
        }
        foreach (['create', 'start', 'stop', 'hard_stop', 'delete'] as $verb) {
            $inputSchema = $schema($verb);
            $inputSchema['required'][] = 'token';
            $inputSchema['properties']['token'] = ['type' => 'string'];
            $kinds['task_vm.upcloud_'.$verb] = ['executor' => 'swamp', 'completion' => TaskVmEffectCompletion::class,
                'runs' => [['transport' => 'task_vm_upcloud', 'name' => 'task_vm.upcloud', 'input_fields' => ['task_vm_id', 'token'],
                    'input_values' => ['action' => $verb], 'input_schema' => $inputSchema, 'secrets' => ['token' => 'upcloud.api']]],
                'completion_contract' => ['step' => 'upcloud-'.str_replace('_', '-', $verb), 'transport' => 'task_vm_upcloud', 'name' => 'task_vm.upcloud', 'result' => ['ready' => true]],
                'result_fields' => ['ready', 'provider_id', 'error_code']];
        }
        $kinds['task_vm.stop_worker'] = ['executor' => 'swamp', 'completion' => TaskVmEffectCompletion::class, 'execution' => TaskVmAgentExecution::class,
            'runs' => [['transport' => 'agent', 'name' => 'swamp.stop-worker', 'input_fields' => [], 'input_values' => []]],
            'completion_contract' => ['step' => 'stop-worker', 'transport' => 'agent', 'name' => 'swamp.stop-worker', 'result' => ['stopped' => true]],
            'result_fields' => ['stopped', 'error_code']];
        foreach (['provision', 'enroll', 'resume', 'destroy'] as $verb) {
            $children = [];
            foreach (['incus', 'upcloud'] as $provider) {
                $effects = ['task_vm.lifecycle_intent', ...match ($verb) {
                    'provision' => ['task_vm.'.$provider.'_create', 'task_vm.prepare_enrollment', 'task_vm.enroll_node', 'task_vm.prepare_runtime'],
                    'enroll' => ['task_vm.prepare_enrollment', 'task_vm.enroll_node', 'task_vm.prepare_runtime'],
                    'resume' => ['task_vm.'.$provider.'_start', 'task_vm.runtime_confirm'],
                    default => ['task_vm.'.$provider.'_delete', 'task_vm.mark_destroying', 'task_vm.forget_workspaces', 'task_vm.destroy_records'],
                }];
                if ($provider === 'upcloud' && $verb === 'provision') {
                    $effects[] = 'task_vm.upcloud_start';
                }
                foreach ([...$effects, ...($verb === 'destroy' ? ['task_vm.resume', 'task_vm.stop_worker', 'task_vm.'.$provider.'_stop', ...($provider === 'upcloud' ? ['task_vm.upcloud_hard_stop'] : []), 'task_vm.model_revoke'] : [])] as $kind) {
                    $from = match (true) {
                        str_starts_with($kind, 'task_vm.incus_') => 'input.host_node_id',
                        str_starts_with($kind, 'task_vm.upcloud_') => 'input.gateway_node_id',
                        in_array($kind, ['task_vm.prepare_runtime', 'task_vm.runtime_confirm', 'task_vm.stop_worker'], true) => 'target.node_id',
                        default => 'input.execution_node_id',
                    };
                    $children[$kind] = ['kind' => $kind, 'node_from' => $from];
                }
            }
            if (in_array($verb, ['provision', 'enroll', 'destroy'], true)) {
                $owner = $verb === 'destroy' ? 'node.remove' : 'node.add';
                $children[$owner] = ['kind' => $owner, 'node_from' => 'intent.lifecycle_node_ids'];
            }
            if ($verb === 'destroy') {
                $children['route.destroy'] = ['kind' => 'route.destroy', 'node_from' => 'intent.workspace_route_node_ids'];
            }
            $kinds['task_vm.'.$verb] = ['executor' => 'swamp', 'completion' => TaskVmLifecycleCompletion::class, 'runs' => [],
                'children' => array_values($children), 'completion_contract' => ['children_succeeded' => ['task_vm.lifecycle_intent']],
                'result_fields' => ['ready', 'node_id', 'error_code']];
        }

        return $kinds;
    }

    /** @return array<string, array<string, mixed>> */
    public static function packageCacheKinds(): array
    {
        return ['task_vm.fill_package_cache' => ['executor' => 'swamp', 'result_fields' => TaskVmPackageCacheProjection::resultFields()]];
    }
}
