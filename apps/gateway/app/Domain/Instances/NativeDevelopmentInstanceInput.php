<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Processes\ProcessLifecycleInput;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Public execution input plus native vault copies; never a Node call or phase callback. */
final readonly class NativeDevelopmentInstanceInput
{
    /** @return array<string,mixed> */
    public function create(Operation $operation): array
    {
        $input = $operation->input;
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        $plan = ValidatedData::object($input['direct_provisioning']);
        $environment = app(DevelopmentCreateEnvironment::class);
        $programs = ValidatedData::object(app(NativeInstanceProvisioningSetup::class)->resolve($operation, $instance->id, '', 'setup_programs')->value);
        $seeded = ($input['seed_path'] ?? null) !== $plan['checkout'];
        $steps = [];
        foreach (InstanceLifecycleInput::members(['members' => $programs['steps']]) as $raw) {
            $step = ValidatedData::object($raw);
            $command = ValidatedData::string($step['command']);
            $steps[] = ['name' => $step['name'], 'command' => $command, 'sha256' => hash('sha256', $command),
                'timeout_seconds' => $step['timeout_seconds'], 'directory' => $plan['checkout'],
                'instance_id' => $instance->id, 'instance_name' => $instance->name,
                'seed_path' => $seeded ? ($input['seed_path'] ?? '') : '', 'seed_commit' => $seeded ? ($input['seed_commit'] ?? '') : ''];
        }
        $vault = app(SwampCommandStep::class);
        $processes = $copies = [];
        foreach (ProcessInstanceOperation::members($operation) as $index => $member) {
            $native = app(ProcessLifecycleInput::class)->forInstanceMember($operation, $member);
            $processes[] = ['index' => $index, 'id' => $member['id'], 'intent' => $native['intent']];
            if (isset($native['execution_secrets']['environment'])) {
                $copies['member_'.$member['id']] = $native['execution_secrets']['environment'];
            }
        }

        return ['intent' => [...$plan, 'ssh_execution' => $input['ssh_execution'],
            'instance_name' => $instance->name, 'name_converted_from' => $input['name_converted_from'] ?? null,
            'processes' => $processes],
            'execution_secrets' => [...$copies,
                'git_read' => app(InstanceGitHubReadTokens::class)->start($operation),
                'environment' => $vault->executionCopy('orbit-control-inputs', 'instance-create-environment-input-'.$operation->id, json_encode([
                    'directory' => $environment->directory($instance), 'checkout' => $instance->checkout_path, 'content' => $environment->content($instance),
                ], JSON_THROW_ON_ERROR)),
                'setup' => $vault->executionCopy('orbit-control-inputs', 'instance-create-'.$operation->id, json_encode(['steps' => $steps], JSON_THROW_ON_ERROR)),
            ]];
    }
}
