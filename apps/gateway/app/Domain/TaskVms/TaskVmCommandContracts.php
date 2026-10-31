<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Support\ValidatedData;
use LogicException;

/** Family-owned command declarations. Kit v2 owns dispatch, binding, hydration and evidence checks. */
final class TaskVmCommandContracts
{
    public const array SpecialGuestCommands = ['agent.install-guest', 'pi.upload-guest'];

    public const array GuestCommands = [
        'guest.cloud-init', 'guest.fingerprint', 'image.owner', 'image.boot', 'image.firewall', 'image.check',
        'image.trust-ca', 'image.clean', 'image.recipe-marker', 'image.inspect-phase', 'image.start-phase',
        'image.restart-phase', 'image.verify',
    ];

    /** @return array<string, mixed> */
    public static function agentInput(string $command): array
    {
        $input = AgentSchemas::inputSchema($command);
        if (! is_array($input)) {
            throw new LogicException("The agent schema for [{$command}] is missing.");
        }

        return ValidatedData::object($input);
    }

    /** @return array<string, mixed> */
    public static function guestParameters(string $command): array
    {
        $schema = match ($command) {
            'agent.install-guest' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [], 'required' => []],
            'pi.upload-guest' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['artifact' => ['type' => 'string'], 'sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']], 'required' => ['artifact', 'sha256']],
            default => self::agentInput($command),
        };

        return $schema;
    }

    /** @return array<string, mixed> */
    public static function guestInput(string $command): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['command', 'guest', 'parameters_json'],
            'properties' => [
                'command' => ['const' => $command],
                'parameters_json' => ['type' => 'string', 'maxLength' => 4_194_304],
                'guest' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['project', 'name'], 'properties' => [
                    'project' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{0,62}$'],
                    'name' => ['type' => 'string', 'pattern' => '^tvm-(?:[0-9]+|image-[0-9]+(?:-attempt-[0-9]+)?)(?:-smoke)?$'],
                ]],
            ],
        ];
    }

    public static function guestKind(string $command): string
    {
        return 'task_vm.guest_'.str_replace(['.', '-'], '_', $command);
    }

    /** @return array<string, array<string, mixed>> */
    public static function guestKinds(): array
    {
        $kinds = [];
        foreach (self::GuestCommands as $command) {
            $kind = self::guestKind($command);
            $fields = ['ready', 'fingerprint', 'state', 'boot_id', 'unit', 'invocation_id', 'exit_code', 'reason', 'error_code'];
            $proof = str_starts_with($command, 'image.') && str_ends_with($command, '-phase') ? [] : ['ready' => true];
            if ($command === 'guest.fingerprint') {
                $proof = [];
            }
            $kinds[$kind] = [
                'executor' => 'swamp', 'completion' => TaskVmImageEffectCompletion::class,
                'runs' => [[
                    'transport' => 'task_vm_guest', 'name' => 'task_vm.guest',
                    'bind' => ['guest' => 'input.guest', 'parameters_json' => 'input.parameters_json'],
                    'input_fields' => [], 'input_values' => ['command' => $command],
                    'input_schema' => self::guestInput($command),
                ]],
                'completion_contract' => ['step' => $command, 'transport' => 'task_vm_guest', 'name' => 'task_vm.guest', 'result' => $proof],
                'result_fields' => $fields,
            ];
        }

        return $kinds;
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
        foreach (['prepare_enrollment' => 'prepare-enrollment', 'enroll_node' => 'enroll', 'confirm_stopped' => 'stopped', 'mark_destroying' => 'destroying', 'forget_workspaces' => 'forget-workspaces', 'destroy_records' => 'forget'] as $verb => $action) {
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
        foreach (['provision', 'enroll', 'resume', 'park', 'destroy'] as $verb) {
            $children = [];
            foreach (['incus', 'upcloud'] as $provider) {
                $effects = ['task_vm.lifecycle_intent', ...match ($verb) {
                    'provision' => ['task_vm.'.$provider.'_create', 'task_vm.prepare_enrollment', 'task_vm.enroll_node', 'task_vm.prepare_runtime'],
                    'enroll' => ['task_vm.prepare_enrollment', 'task_vm.enroll_node', 'task_vm.prepare_runtime'],
                    'resume' => ['task_vm.'.$provider.'_start', 'task_vm.runtime_confirm'],
                    'park' => ['task_vm.'.$provider.'_stop', 'task_vm.confirm_stopped'],
                    default => ['task_vm.'.$provider.'_delete', 'task_vm.mark_destroying', 'task_vm.forget_workspaces', 'task_vm.destroy_records'],
                }];
                if ($provider === 'upcloud' && $verb === 'provision') {
                    $effects[] = 'task_vm.upcloud_start';
                }
                if ($provider === 'upcloud' && $verb === 'park') {
                    $effects[] = 'task_vm.upcloud_hard_stop';
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

    /** @return array<string, array<string, mixed>> */
    public static function imageKinds(): array
    {
        $kinds = [];
        foreach ([
            'image_remove' => ['incus.vm', ['vm', 'operation']], 'image_launch' => ['incus.vm', ['vm', 'operation']],
            'image_metadata' => ['incus.image', ['project', 'operation']], 'image_publish' => ['incus.image', ['project', 'operation']],
            'image_seed' => ['incus.build-topology-seed', ['project', 'pool', 'vm', 'size', 'fingerprint']],
            'image_finish_seed' => ['incus.volume', ['location', 'operation']],
        ] as $verb => [$command, $fields]) {
            $schema = self::agentInput($command);
            $properties = ValidatedData::object($schema['properties']);
            $properties['command'] = ['const' => $command];
            $schema['properties'] = $properties;
            $schema['required'] = [...ValidatedData::stringList($schema['required']), 'command'];
            $kinds['task_vm.'.$verb] = self::imageDefinition($verb, 'task_vm.image-host', $fields, ['command' => $command], $schema);
        }
        foreach (['image_promote', 'image_prune'] as $verb) {
            $kinds['task_vm.'.$verb] = self::imageDefinition($verb, 'task_vm.image-host', ['build_id'], [], ['type' => 'object', 'additionalProperties' => false,
                'required' => ['build_id'], 'properties' => ['build_id' => ['type' => 'integer', 'minimum' => 1]]]);
        }
        foreach (['guest_install' => 'agent.install-guest', 'guest_pi_upload' => 'pi.upload-guest'] as $verb => $command) {
            $kinds['task_vm.'.$verb] = self::imageDefinition($verb, 'task_vm.guest', $verb === 'guest_install' ? ['guest'] : ['guest', 'parameters_json'],
                $verb === 'guest_install' ? ['command' => $command, 'parameters_json' => '{}'] : ['command' => $command], self::guestInput($command));
        }
        $kinds['task_vm.image_intent'] = self::imageDefinition('image_intent', 'task_vm.image-phase', ['build_id'], [], ['type' => 'object', 'additionalProperties' => false, 'required' => ['build_id'], 'properties' => ['build_id' => ['type' => 'integer', 'minimum' => 1]]]);
        $kinds['task_vm.image_intent']['completion'] = TaskVmEffectCompletion::class;
        $inspect = self::agentInput('image.inspect-phase');
        $properties = ValidatedData::object($inspect['properties']);
        unset($properties['boot_id']);
        $schema = [...$inspect, 'properties' => $properties, 'required' => ['key']];
        $kinds['task_vm.image_phase_intent'] = self::imageDefinition('image_phase_intent', 'task_vm.image-phase', ['key'], [], $schema);
        $kinds['task_vm.image_phase_intent']['completion'] = TaskVmEffectCompletion::class;
        $kinds['task_vm.image_phase_confirm'] = self::imageDefinition('image_phase_confirm', 'task_vm.image-phase', ['key', 'boot_id'], [], self::agentInput('image.inspect-phase'));
        $children = array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input.host_node_id'], [...array_keys($kinds), ...array_keys(self::guestKinds())]);
        $kinds['task_vm.image_build'] = ['executor' => 'swamp', 'completion' => TaskVmImageCompletion::class, 'runs' => [], 'children' => $children,
            'completion_contract' => ['children_succeeded' => ['task_vm.image_prune']], 'result_fields' => ['ready', 'fingerprint', 'error_code']];

        return $kinds;
    }

    /** @param list<string> $fields
     * @param  array<string, mixed>  $fixed
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function imageDefinition(string $verb, string $name, array $fields, array $fixed, array $schema): array
    {
        return ['executor' => 'swamp', 'completion' => TaskVmImageEffectCompletion::class,
            'runs' => [['transport' => str_replace('.', '_', $name), 'name' => $name, 'input_fields' => $fields, 'input_values' => $fixed, 'input_schema' => $schema]],
            'completion_contract' => ['step' => str_replace('_', '-', $verb), 'transport' => str_replace('.', '_', $name), 'name' => $name, 'result' => ['ready' => true]],
            'result_fields' => ['ready', 'fingerprint', 'name', 'boot_id', 'error_code']];
    }
}
