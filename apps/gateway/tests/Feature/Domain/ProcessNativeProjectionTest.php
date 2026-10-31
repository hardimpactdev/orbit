<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Processes\ProcessInstallationProjection;
use App\Domain\Processes\ProcessOperationCompletion;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'native-process', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9']);
    $this->process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'native',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/sleep', '300']], 'restart_policy' => 'never',
        'desired_state' => 'running', 'status' => 'provisioning', 'error_code' => 'process.old_failure', 'failed_step' => 'retired-step']);
    $this->process->forceFill(['systemd_activation_hash' => str_repeat('a', 64), 'systemd_activation_pending' => true,
        'retired_runtime' => 'docker'])->save();
    $this->operation = Operation::query()->create(['kind' => 'process.create', 'target_type' => 'process', 'target_id' => $this->process->id,
        'node_id' => $node->id, 'status' => 'running', 'input' => ['process_id' => $this->process->id, 'content_hash' => str_repeat('b', 64), 'created' => true]]);
    $this->hook = new class implements ProcessInstallationProjection
    {
        /** @var list<array{operation_id:int,process_id:int,outcome:OperationOutcome}> */
        public array $calls = [];

        public function complete(Operation $operation, Process $process, OperationOutcome $outcome): void
        {
            $this->calls[] = ['operation_id' => $operation->id, 'process_id' => $process->id, 'outcome' => $outcome];
        }
    };
    $this->projection = new ProcessOperationCompletion(app(RecordEventBroadcaster::class), app(ProcessRuntimeStatusIndex::class), [$this->hook]);
});

it('records the admitted activation generation and supplies the native outcome to installation owners', function (): void {
    $outcome = OperationOutcome::succeeded(['ready' => true, 'state' => 'active']);
    $this->projection->complete($this->operation, $outcome);
    $process = $this->process->refresh();
    expect($process->status->value)->toBe('active')
        ->and($process->desired_state->value)->toBe('running')
        ->and($process->systemd_activation_hash)->toBe(str_repeat('b', 64))
        ->and($process->systemd_activation_pending)->toBeFalse()
        ->and($process->retired_runtime)->toBeNull()
        ->and($process->error_code)->toBeNull()
        ->and($process->failed_step)->toBeNull()
        ->and($this->hook->calls)->toBe([['operation_id' => $this->operation->id, 'process_id' => $process->id, 'outcome' => $outcome]])
        ->and(Operation::query()->count())->toBe(1);
});

it('retains the installed generation and desired state when native installation fails or is cancelled', function (string $status): void {
    $outcome = $status === 'failed' ? OperationOutcome::failed('operation.agent_invalid') : OperationOutcome::cancelled();
    $this->operation->update(['kind' => 'process.update']);
    $this->projection->complete($this->operation, $outcome);
    $process = $this->process->refresh();
    expect($process->status->value)->toBe('failed')
        ->and($process->desired_state->value)->toBe('running')
        ->and($process->systemd_activation_hash)->toBe(str_repeat('a', 64))
        ->and($process->systemd_activation_pending)->toBeTrue()
        ->and($process->retired_runtime)->not->toBeNull()
        ->and($process->error_code)->toBe($outcome->errorCode)
        ->and($process->failed_step)->toBeNull()
        ->and($this->hook->calls[0]['outcome'])->toBe($outcome);
})->with(['failed', 'cancelled']);

it('changes desired state only after a successful native control outcome', function (string $verb, string $desired): void {
    $this->operation->update(['kind' => 'process.'.$verb, 'input' => ['process_id' => $this->process->id]]);
    $this->projection->complete($this->operation, OperationOutcome::succeeded(['ready' => true, 'state' => $desired === 'running' ? 'active' : 'inactive']));
    expect($this->process->refresh()->desired_state->value)->toBe($desired)
        ->and($this->hook->calls)->toBe([]);
})->with([['start', 'running'], ['restart', 'running'], ['stop', 'stopped']]);

it('removes only the record selected by a successful native destroy outcome', function (bool $preserve): void {
    $this->operation->update(['kind' => 'process.destroy', 'input' => ['process_id' => $this->process->id, 'preserve_record' => $preserve]]);
    $this->projection->complete($this->operation, OperationOutcome::succeeded(['ready' => true]));
    expect(Process::query()->whereKey($this->process->id)->exists())->toBe($preserve)
        ->and($this->hook->calls)->toBe([]);
})->with([false, true]);
