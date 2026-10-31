<?php

declare(strict_types=1);

use App\Actions\Processes\UpdateProcessAction;
use App\Data\Processes\AddProcessData;
use App\Data\Processes\UpdateProcessData;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStore;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessOperationCompletion;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->node = Node::query()->create(['name' => 'recorded-update', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.55', 'public_ssh_host' => '192.0.2.55']);
    $this->process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'native-control',
        'runtime' => ProcessRuntime::Systemd, 'working_directory' => '/srv/old',
        'runtime_config' => ['command' => ['/usr/bin/sleep', '60'], 'environment_file' => '', 'environment' => ['TOKEN' => 'private-recorded-value'], 'required_mount_paths' => ['/srv/state']],
        'restart_policy' => 'always', 'desired_state' => DesiredProcessState::Running, 'status' => LifecycleStatus::Active,
        'systemd_activation_pending' => true, 'systemd_activation_hash' => str_repeat('a', 64),
    ]);
    $this->process->forceFill(['systemd_activation_pending' => true, 'systemd_activation_hash' => str_repeat('a', 64)])->save();
    $this->process->refresh();
});

function recordedProcessUpdate(Node $node): UpdateProcessData
{
    return new UpdateProcessData(new AddProcessData(
        targetType: ProcessTargetType::Node, targetId: $node->id, name: 'native-control',
        runtime: ProcessRuntime::Systemd, command: ['/usr/bin/sleep', '120'], image: null,
        workingDirectory: '/srv/new', environment: [], ports: [], volumes: [], restartPolicy: 'always', start: false,
    ), ['working_directory', 'command']);
}

it('records a changed existing spec without native admission or effects and preserves omitted fields', function (): void {
    $record = app(UpdateProcessAction::class)->recordIntent($this->process, recordedProcessUpdate($this->node));

    expect($record->id)->toBe($this->process->id)
        ->and($record->working_directory)->toBe('/srv/new')
        ->and($record->runtime_config['command'])->toBe(['/usr/bin/sleep', '120'])
        ->and($record->runtime_config['environment'])->toBe(['TOKEN' => 'private-recorded-value'])
        ->and($record->runtime_config['required_mount_paths'])->toBe(['/srv/state'])
        ->and($record->desired_state)->toBe(DesiredProcessState::Running)
        ->and($record->status)->toBe(LifecycleStatus::Provisioning)
        ->and($record->systemd_activation_hash)->toBe(str_repeat('a', 64))
        ->and(Operation::query()->count())->toBe(0)
        ->and($this->runtime->started)->toBeEmpty()
        ->and($this->runtime->convergedProcessIds)->toBeEmpty();
});

it('uses the normal preset refusal before changing recorded intent', function (): void {
    $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'preset' => 'vp-dev']]);
    $original = $this->process->refresh()->getRawOriginal();

    expect(fn () => app(UpdateProcessAction::class)->recordIntent($this->process, recordedProcessUpdate($this->node)))
        ->toThrow(ResourceOperationException::class, 'Preset Processes cannot be updated.');

    expect($this->process->refresh()->getRawOriginal())->toBe($original)
        ->and(Operation::query()->count())->toBe(0);
});

it('rolls back recorded intent when the real parent Operation store refuses', function (): void {
    $original = $this->process->getRawOriginal();

    expect(fn () => DB::transaction(function (): void {
        app(UpdateProcessAction::class)->recordIntent($this->process, recordedProcessUpdate($this->node));
        app(OperationStore::class)->create('process.update', 'process', $this->process->id, null, [], 'gateway-maintenance', null);
    }))->toThrow(ResourceOperationException::class, 'A direct Operation requires its admitted root Node.');

    expect($this->process->refresh()->getRawOriginal())->toBe($original)
        ->and(Operation::query()->count())->toBe(0);
});

it('projects a verified parent success without a synthetic Operation or native run', function (): void {
    $record = app(UpdateProcessAction::class)->recordIntent($this->process, recordedProcessUpdate($this->node));
    $input = app(ProcessOperationAdmission::class)->inputFor($record, 'update');

    app(ProcessOperationCompletion::class)->completeRecordedUpdate($record, $input, OperationOutcome::succeeded(['ready' => true, 'state' => 'active']));

    expect($record->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and($record->systemd_activation_hash)->toBe($input['content_hash'])
        ->and($record->systemd_activation_pending)->toBeFalse()
        ->and($record->desired_state)->toBe(DesiredProcessState::Running)
        ->and(json_encode($input))->not->toContain('private-recorded-value')
        ->and(Operation::query()->count())->toBe(0);
});

it('retains the staged definition and prior activation when the parent reports failure', function (): void {
    $record = app(UpdateProcessAction::class)->recordIntent($this->process, recordedProcessUpdate($this->node));
    $input = app(ProcessOperationAdmission::class)->inputFor($record, 'update');

    app(ProcessOperationCompletion::class)->completeRecordedUpdate($record, $input, OperationOutcome::failed('process.start_failed'));

    expect($record->refresh()->status)->toBe(LifecycleStatus::Failed)
        ->and($record->working_directory)->toBe('/srv/new')
        ->and($record->error_code)->toBe('process.start_failed')
        ->and($record->systemd_activation_hash)->toBe(str_repeat('a', 64))
        ->and($record->systemd_activation_pending)->toBeTrue()
        ->and(Operation::query()->count())->toBe(0);
});

it('records a real foreground maintenance Operation without submitting a native run', function (): void {
    $operation = DB::transaction(function (): Operation {
        $record = app(UpdateProcessAction::class)->recordIntent($this->process, recordedProcessUpdate($this->node));
        $input = app(ProcessOperationAdmission::class)->inputFor($record, 'update');
        $input['installation'] = ['$secret' => 'process.installation', 'id' => $record->id];

        return app(OperationStore::class)->create('process.update', 'process', $record->id, $this->node->id, $input, 'gateway-maintenance', null);
    });

    expect($operation->id)->toBeInt()->toBeGreaterThan(0)
        ->and($operation->state)->not->toHaveKey('swamp_run_id')
        ->and($operation->input['content_hash'])->toBeString()
        ->and(Operation::query()->count())->toBe(1)
        ->and($this->runtime->convergedProcessIds)->toBeEmpty();
});
