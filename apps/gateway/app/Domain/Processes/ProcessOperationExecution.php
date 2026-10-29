<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Public descriptors and receipt proof only. Swamp orders and executes runtime work. */
final readonly class ProcessOperationExecution implements OperationFamilyChildren, OperationFamilyExecution, OperationGatewayCommand
{
    public function __construct(private ProcessTargetResolver $targets, private ProcessOperationCompletion $completion) {}

    public function context(Operation $operation): array
    {
        $input = $operation->input;
        $verb = substr($operation->kind, strlen('process.'));
        $descriptors = [];
        $add = static function (string $key, string $name, array $input, bool $resumable = true, bool $required = true) use (&$descriptors): void {
            $descriptors[] = ['key' => $key, 'step' => $key, 'transport' => 'agent', 'name' => $name,
                'input' => $input, 'resumable' => $resumable, 'required' => $required];
        };
        $artifact = Arr::only($input, ['process_id', 'runtime', 'name']);
        if ($verb === 'destroy') {
            $add('process-remove', 'process.remove', [...$artifact, 'vite' => $input['vite']]);
        } else {
            if (isset($input['directory'])) {
                $add('process-check-release', 'process.check-release', Arr::only($input, ['directory']));
            }
            $plan = $input['vite_plan'] ?? null;
            if (is_array($plan)) {
                $add('vite-suspend', 'vite.set-traffic', ValidatedData::object($plan['suspend']));
                if ($verb === 'restart') {
                    $add('vite-stop', 'process.control', [...$artifact, 'on_demand' => $input['on_demand'], 'operation' => 'stop']);
                }
                $add('vite-environment', 'vite.set-traffic', ValidatedData::object($plan['environment']));
                if ($input['desired'] === 'running' || in_array($verb, ['start', 'restart'], true)) {
                    for ($check = 0; $check < 60; $check++) {
                        $add('vite-ready-'.$check, 'vite.check-listener', ValidatedData::object($plan['listener']), required: false);
                    }
                    $add('vite-stop-unready', 'process.control', [...$artifact, 'on_demand' => $input['on_demand'], 'operation' => 'stop'], required: false);
                }
                if ($verb === 'start') {
                    $add('vite-awake', 'vite.set-traffic', ValidatedData::object($plan['awake']));
                }
            }
            if (in_array($verb, ['start', 'stop', 'restart'], true)) {
                $add('process-'.$verb, 'process.control', [...$artifact, 'on_demand' => $input['on_demand'], 'operation' => $verb], $verb !== 'restart');
            } elseif (in_array($verb, ['create', 'update'], true)) {
                if (isset($input['retired_runtime'])) {
                    $add('process-remove-retired', 'process.remove', ['process_id' => $input['process_id'], 'runtime' => $input['retired_runtime'], 'name' => $input['retired_name'], 'vite' => null]);
                }
                if ($input['runtime'] === 'docker') {
                    $add('process-converge-container', 'process.converge-container', Arr::only($input, ['process_id', 'container', 'desired', 'spec', 'image', 'command', 'restart', 'working_directory', 'ports', 'mounts', 'environment']));
                } else {
                    $add('process-stage-unit', 'process.stage-unit', Arr::only($input, ['process_id', 'unit', 'content']));
                    $steps = ValidatedData::object($operation->state['steps'] ?? []);
                    $stage = $steps['process-stage-unit'] ?? null;
                    if (is_array($stage)) {
                        $settled = ($stage['state'] ?? null) === ($input['desired'] === 'running' ? 'active' : 'inactive');
                        $required = ($stage['staged'] ?? null) !== false || ($input['activation_pending'] ?? false) || ! $settled;
                        $add('process-activate-unit', 'process.activate-unit', [...Arr::only($input, ['process_id', 'unit', 'desired', 'on_demand']),
                            'change' => ($stage['staged'] ?? false) ? (($stage['previous'] ?? false) ? 'replace' : 'create') : 'reload'], required: $required);
                    }
                }
            } else {
                throw new ResourceOperationException('operation.execution_forbidden', 'Unknown Process action.', 422);
            }
        }
        if ($verb !== 'destroy') {
            $add('process-status', 'process.status', $artifact);
        }
        $descriptors[] = ['key' => 'runtime-ready', 'step' => 'runtime-ready', 'transport' => 'gateway', 'name' => 'process.runtime_ready',
            'input' => ['process_id' => $input['process_id']], 'resumable' => true, 'required' => true];

        return ['descriptors' => $descriptors];
    }

    public function input(Operation $operation, array $run): array
    {
        $descriptor = array_find($this->context($operation)['descriptors'], static fn (array $entry): bool => $entry['key'] === $run['key'] && $entry['name'] === $run['name']);
        if ($descriptor === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Process step is not admitted.', 422);
        }

        return $descriptor['input'];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $process = Process::query()->whereKey($operation->target_id)->firstOrFail();
        $target = $operation->kind === 'process.destroy' ? $this->targets->forRemoval($process) : $this->targets->forInstallation($process);
        if ($operation->target_type !== 'process' || $process->id !== ($operation->input['process_id'] ?? null)
            || $target->node->id !== $operation->node_id || $process->runtime->value !== ($operation->input['runtime'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Process placement changed after admission.', 409);
        }
        if ($run['name'] === 'process.status') {
            $this->completion->assertEffects($operation, requireStatus: false);
        }
        if ($run['name'] === 'process.runtime_ready') {
            $this->completion->assertEffects($operation);
        }
    }

    public function childContext(Operation $parent): array
    {
        return ['intent' => [], 'children' => isset($parent->input['vite_plan']) ? [[
            'key' => 'vite-caddy', 'kind' => 'route.caddy_converge', 'node_id' => $parent->node_id, 'input' => ['reason' => 'vite_port'],
        ]] : []];
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['process_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['process_id'], 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $this->completion->assertEffects($operation);
        $steps = ValidatedData::object($operation->state['steps'] ?? []);
        $result = ValidatedData::object($steps['process-status'] ?? []);

        return ['ready' => true, 'state' => ValidatedData::string($result['state'] ?? 'unknown')];
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['name'] === 'process.stage-unit' && ($receipt['staged'] ?? null) === true) {
            Process::query()->whereKey($operation->target_id)->update(['systemd_activation_pending' => true]);
        }

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        $verb = substr($operation->kind, strlen('process.'));
        $key = match ($verb) {
            'create', 'update' => $operation->input['runtime'] === 'docker' ? 'process-converge-container' : 'process-activate-unit',
            'destroy' => 'process-remove',
            default => 'process-'.$verb,
        };
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        $receipt = $runs[$key] ?? null;
        if (! is_array($receipt) || ! is_array($receipt['result'] ?? null) || isset($receipt['result']['error_code'])) {
            return null;
        }

        return ['key' => $key, 'transport' => 'agent', 'name' => ValidatedData::string($receipt['name']), 'result' => ValidatedData::object($receipt['result'])];
    }
}
