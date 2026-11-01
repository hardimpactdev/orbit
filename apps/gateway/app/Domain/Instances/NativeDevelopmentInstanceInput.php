<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Public execution input plus native vault copies; never a Node call or phase callback. */
final readonly class NativeDevelopmentInstanceInput
{
    /** @return array{vault:string,key:string} */
    public static function environmentOutput(Operation $operation): array
    {
        return ['vault' => 'orbit-control-inputs', 'key' => 'instance-create-environment-result-'.$operation->id];
    }

    /** @return array<string,mixed> */
    public function create(Operation $operation): array
    {
        $input = $operation->input;
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        $plan = ValidatedData::object($input['direct_provisioning']);
        $ssh = $input['ssh_execution'];
        $values = app(InstanceEnvironmentStore::class)->values($instance);
        $material = new OperationSecretValue(['values' => $values, 'name' => $instance->project->name, 'framework_key' => null]);
        $base = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'instance-create-environment-input-'.$operation->id, json_encode($material->value, JSON_THROW_ON_ERROR));
        $programs = ValidatedData::object(app(NativeInstanceProvisioningSetup::class)->resolve($operation, $instance->id, '', 'setup_programs')->value);
        $copies = ['git_read' => InstanceGitHubReadTokens::reference($operation), 'environment' => $base];
        $commands = [];
        foreach (InstanceLifecycleInput::members(['members' => $programs['steps']]) as $index => $raw) {
            $step = ValidatedData::object($raw);
            $field = 'setup_'.$index;
            $sha = hash('sha256', ValidatedData::string($step['command']));
            $commands[] = ['index' => $index, 'name' => $step['name'], 'command_digest' => $sha, 'timeout_seconds' => $step['timeout_seconds'], 'secret_field' => $field];
            $copies[$field] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'instance-create-'.$operation->id.'-setup-'.$index, json_encode([
                'command' => $step['command'], 'sha256' => $sha, 'directory' => $plan['checkout'], 'instance_id' => $instance->id, 'instance_name' => $instance->name,
                'seed_path' => ($input['seed_path'] ?? null) !== $plan['checkout'] ? ($input['seed_path'] ?? '') : '',
                'seed_commit' => ($input['seed_path'] ?? null) !== $plan['checkout'] ? ($input['seed_commit'] ?? '') : '',
            ], JSON_THROW_ON_ERROR));
        }

        return ['intent' => [...$plan, 'ssh_execution' => $ssh,
            'setup_commands' => $commands, 'instance_name' => $instance->name, 'name_converted_from' => $input['name_converted_from'] ?? null,
            'vp_setup' => app(ToolManagerIntent::class)->nativeInput($instance->node, ToolManagerName::Vp),
            'environment_output' => self::environmentOutput($operation),
            'execution_references_json' => json_encode(['git_read' => InstanceGitHubReadTokens::reference($operation)], JSON_THROW_ON_ERROR)],
            'execution_secrets' => $copies];
    }
}
