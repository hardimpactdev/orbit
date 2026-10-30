<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Data\Processes\ProcessData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;

/** Projects proven runtime results; never executes on a Node. */
final readonly class ProcessOperationCompletion implements OperationCompletion
{
    public function __construct(private RecordEventBroadcaster $broadcaster, private ProcessRuntimeStatusIndex $statuses) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $process = Process::query()->whereKey($operation->input['process_id'])->firstOrFail();
        if ($outcome->status !== OperationStatus::Succeeded) {
            $process->update(['status' => $operation->kind === 'process.destroy' ? LifecycleStatus::Removing : LifecycleStatus::Failed,
                'error_code' => $outcome->errorCode, 'failed_step' => $operation->steps()->where('status', 'failed')->value('name')]);

            return;
        }
        if (! DirectOperation::owns($operation)) {
            $this->assertEffects($operation);
        }
        if ($operation->kind === 'process.destroy') {
            if (! $operation->input['preserve_record']) {
                $process->delete();
                $this->broadcaster->broadcast(RecordEventType::ProcessDeleted, $process->id, ['id' => $process->id, 'name' => $process->name]);
            }

            return;
        }
        $desired = match ($operation->kind) {
            'process.start', 'process.restart' => DesiredProcessState::Running,
            'process.stop' => DesiredProcessState::Stopped,
            default => $process->desired_state,
        };
        $process->update(['status' => LifecycleStatus::Active, 'desired_state' => $desired, 'failed_step' => null, 'error_code' => null,
            'retired_runtime' => null, ...isset($operation->input['content_hash']) ? ['systemd_activation_hash' => $operation->input['content_hash'], 'systemd_activation_pending' => false] : []]);
        $this->statuses->remember($process, ValidatedData::string($outcome->result['state'] ?? 'unknown'));
        $this->broadcaster->broadcast(($operation->input['created'] ?? false) ? RecordEventType::ProcessCreated : RecordEventType::ProcessStatus,
            $process->id, ProcessData::fromModel($process, ValidatedData::string($outcome->result['state'] ?? 'unknown'))->toArray());
    }

    public function assertEffects(Operation $operation, bool $requireStatus = true): void
    {
        $verb = substr($operation->kind, strlen('process.'));
        $required = match ($verb) {
            'destroy' => ['process-remove' => 'process.remove'],
            'create', 'update' => $operation->input['runtime'] === 'docker'
                ? ['process-converge-container' => 'process.converge-container']
                : ['process-stage-unit' => 'process.stage-unit'],
            default => ['process-'.$verb => 'process.control'],
        };
        if ($requireStatus && $verb !== 'destroy') {
            $required['process-status'] = 'process.status';
        }
        if (isset($operation->input['retired_runtime'])) {
            $required['process-remove-retired'] = 'process.remove';
        }
        if (isset($operation->input['directory'])) {
            $required['process-check-release'] = 'process.check-release';
        }
        $steps = ValidatedData::object($operation->state['steps'] ?? []);
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        $staged = $steps['process-stage-unit'] ?? null;
        if (is_array($staged)) {
            $settled = $operation->input['desired'] === 'running' ? ($staged['state'] ?? null) === 'active' : ($staged['state'] ?? null) === 'inactive';
            if (($staged['staged'] ?? null) !== false || $operation->input['activation_pending'] || ! $settled) {
                $required['process-activate-unit'] = 'process.activate-unit';
            }
        }
        if (isset($operation->input['vite_plan'])) {
            $required += ['vite-suspend' => 'vite.set-traffic', 'vite-environment' => 'vite.set-traffic'];
            if ($verb === 'restart') {
                $required['vite-stop'] = 'process.control';
            }
            if ($verb === 'start') {
                $required['vite-awake'] = 'vite.set-traffic';
            }
            app(OperationChildren::class)->requireSucceeded($operation, 'vite-caddy');
            if ($operation->input['desired'] === 'running' || in_array($verb, ['start', 'restart'], true)) {
                $ready = array_find(array_keys($steps), static fn ($key): bool => str_starts_with($key, 'vite-ready-') && is_array($steps[$key]) && ($steps[$key]['ready'] ?? false) === true && ($steps[$key]['owned'] ?? false) === true);
                if ($ready === null) {
                    throw new ResourceOperationException('operation.completion_unproven', 'An owned Vite listener must be proven ready.', 409);
                }
                $required[$ready] = 'vite.check-listener';
            }
        }
        $release = $steps['process-check-release'] ?? null;
        $activation = $steps['process-activate-unit'] ?? null;
        if ((isset($operation->input['directory']) && (! is_array($release) || ($release['available'] ?? null) !== true))
            || (is_array($activation) && ($activation['backup_left'] ?? false) === true)) {
            throw new ResourceOperationException('operation.completion_unproven', 'The runtime receipt does not prove a settled Process.', 409);
        }
        foreach ($required as $step => $command) {
            $run = $runs[$step] ?? null;
            $result = $steps[$step] ?? null;
            if (! is_array($run) || $run['transport'] !== 'agent' || $run['name'] !== $command
                || ! is_array($result) || $run['result'] !== $result || isset($result['error_code'])
                || ! $operation->steps()->where('name', $step)->where('status', 'succeeded')->exists()) {
                throw new ResourceOperationException('operation.completion_unproven', 'A successful Process effect receipt is required.', 409);
            }
        }
    }
}
