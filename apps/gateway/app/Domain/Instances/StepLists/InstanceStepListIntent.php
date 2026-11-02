<?php

declare(strict_types=1);

namespace App\Domain\Instances\StepLists;

use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Rechecks the admitted list and builds the run inputs: every command, in order, with its account and checkout. */
final readonly class InstanceStepListIntent
{
    public function __construct(private StartInstanceStepListAction $admission, private ProjectLifecycleStepStore $steps) {}

    /** @return array{intent:array<string,mixed>,execution_secrets:array<never,never>} */
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $phase = is_string($input['list'] ?? null) ? LifecyclePhase::tryFrom($input['list']) : null;
        $instance = Instance::query()->with(['project', 'node'])->findOrFail($operation->target_id);
        if ($operation->kind !== 'instance.run_steps' || $operation->target_type !== 'instance'
            || $operation->node_id !== $instance->node_id || ($input['instance_id'] ?? null) !== $instance->id
            || ($input['source'] ?? null) !== 'project' || $phase === null
            || ($input['configuration_digest'] ?? null) !== $this->admission->configurationDigest($instance->project, $phase)) {
            throw $this->refused();
        }
        $admitted = $this->admission->appStepAdmission($instance, $phase);
        foreach ($admitted as $field => $value) {
            if (($input[$field] ?? null) !== $value) {
                throw $this->refused();
            }
        }

        $execution = ValidatedData::object($admitted['execution']);
        $steps = [];
        foreach ($this->steps->ordered($instance->project, $phase) as $step) {
            $command = GatewaySetupSteps::owns($step->command) ? GatewaySetupSteps::script($step->command) : $step->command;
            $steps[] = ['name' => $step->name, 'command' => $command, 'sha256' => hash('sha256', $command),
                'timeout_seconds' => $step->timeoutSeconds, 'directory' => $execution['directory'],
                'instance_id' => $instance->id, 'instance_name' => $instance->name,
                'seed_path' => $execution['seed_path'], 'seed_commit' => $execution['seed_commit']];
        }

        return ['intent' => ['instance_id' => $instance->id, 'list' => $phase->value, 'user' => $execution['user'],
            'directory' => $execution['directory'], 'steps' => $steps,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)], 'execution_secrets' => []];
    }

    private function refused(): ResourceOperationException
    {
        return new ResourceOperationException('instance.step_not_admitted', 'The Project list does not match its admitted target and configuration.', 409);
    }
}
