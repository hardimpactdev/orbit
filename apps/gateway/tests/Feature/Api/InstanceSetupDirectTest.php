<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Instances\StepLists\InstanceSetupProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProjectLifecycleStep;

beforeEach(function (): void {
    $this->instance = dev935_instance();
    $this->instance->node->update(['user' => 'nckrtl']);
    $this->instance->update(['production_user' => null, 'production_home' => null, 'checkout_path' => '/fast/apps/setup-proof/default']);
    app(ProjectLifecycleStepStore::class)->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('install', 'private setup program', 30), null, null);
    $admission = app(StartInstanceStepListAction::class);
    $this->operation = Operation::query()->create(['kind' => 'instance.run_steps', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->instance->node_id, 'status' => OperationStatus::Running,
        'input' => ['instance_id' => $this->instance->id, 'source' => 'project', 'list' => 'setup',
            'configuration_digest' => $admission->configurationDigest($this->instance->project, LifecyclePhase::Setup), ...$admission->appStepAdmission($this->instance, LifecyclePhase::Setup)],
        'state' => ['instance_step_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
});

it('passes every command, its account and checkout as run inputs, with an owned macro expanded', function (): void {
    app(ProjectLifecycleStepStore::class)->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('caches', LifecycleStep::ImportCiCaches, 60), null, null);
    $admission = app(StartInstanceStepListAction::class);
    $this->operation->forceFill(['input' => [...$this->operation->input, 'configuration_digest' => $admission->configurationDigest($this->instance->project, LifecyclePhase::Setup),
        ...$admission->appStepAdmission($this->instance, LifecyclePhase::Setup)]])->save();
    $input = app(InstanceSetupProjection::class)->input($this->operation);
    $caches = GatewaySetupSteps::script(LifecycleStep::ImportCiCaches);
    expect($input['execution_secrets'])->toBe([])
        ->and(array_diff_key($input['intent'], ['ssh_execution' => true]))->toBe(['instance_id' => $this->instance->id, 'list' => 'setup', 'user' => 'nckrtl',
            'directory' => '/fast/apps/setup-proof/default', 'steps' => [
                ['name' => 'install', 'command' => 'private setup program', 'sha256' => hash('sha256', 'private setup program'), 'timeout_seconds' => 30,
                    'directory' => '/fast/apps/setup-proof/default', 'instance_id' => $this->instance->id, 'instance_name' => $this->instance->name, 'seed_path' => '', 'seed_commit' => ''],
                ['name' => 'caches', 'command' => $caches, 'sha256' => hash('sha256', $caches), 'timeout_seconds' => 60,
                    'directory' => '/fast/apps/setup-proof/default', 'instance_id' => $this->instance->id, 'instance_name' => $this->instance->name, 'seed_path' => '', 'seed_commit' => ''],
            ]])
        ->and($input['intent']['ssh_execution']['target']['host'])->toBe($this->instance->node->wireguard_ip);
});

it('refuses a moved target and changed release or setup after admission', function (string $change): void {
    match ($change) {
        'node' => $this->operation->update(['node_id' => Node::query()->create(['name' => 'other-app-node', 'public_ssh_host' => '192.0.2.111', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active'])->id]),
        'release' => $this->instance->update(['checkout_path' => '/fast/apps/setup-proof/changed']),
        'command' => null,
        default => null,
    };
    if ($change === 'command') {
        ProjectLifecycleStep::query()->where('project_id', $this->instance->project_id)->update(['command' => 'changed private command']);
    }
    expect(fn () => app(InstanceSetupProjection::class)->input($this->operation->fresh()))->toThrow(ResourceOperationException::class);
})->with(['node', 'release', 'command']);

it('projects bounded success and refuses a secret-bearing result', function (): void {
    $projection = app(InstanceSetupProjection::class);
    $data = ['run_id' => $this->operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => '2026-10-30T18:00:00.000Z', 'finished_at' => '2026-10-30T18:00:01.000Z',
        'result' => ['instance_id' => $this->instance->id, 'outcome' => 'succeeded', 'no_op' => false], 'error_code' => null];
    $bad = $data;
    $bad['result']['program'] = 'private setup program';
    expect(fn () => $projection->apply($this->operation, $bad))->toThrow(ResourceOperationException::class);
    $this->instance->update(['first_setup_pending' => true, 'failed_step' => 'setup', 'error_code' => 'old.setup_error']);
    expect($projection->apply($this->operation, $data)->status)->toBe(OperationStatus::Succeeded)
        ->and($this->instance->fresh()->first_setup_pending)->toBeFalse()
        ->and($this->instance->fresh()->failed_step)->toBeNull()->and($this->instance->fresh()->error_code)->toBeNull();
    $this->instance->refresh()->update(['first_setup_pending' => true, 'error_code' => 'newer.setup_error']);
    $projection->apply($this->operation->fresh(), $data);
    expect($this->instance->fresh()->first_setup_pending)->toBeTrue()->and($this->instance->fresh()->error_code)->toBe('newer.setup_error');
});

it('projects an app setup failure to the Instance', function (): void {
    $this->instance->update(['first_setup_pending' => true]);
    app(InstanceSetupProjection::class)->apply($this->operation, [
        'run_id' => $this->operation->state['swamp_run_id'], 'status' => 'failed', 'started_at' => now()->subSecond()->toISOString(), 'finished_at' => now()->toISOString(),
        'result' => ['instance_id' => $this->instance->id, 'outcome' => 'failed', 'error_code' => 'instance.setup_step_failed', 'failed_step' => 'setup.install'], 'error_code' => 'instance.setup_step_failed',
    ]);
    expect($this->instance->fresh()->first_setup_pending)->toBeTrue()->and($this->instance->fresh()->failed_step)->toBe('setup')->and($this->instance->fresh()->error_code)->toBe('instance.setup_step_failed');
});

it('passes the teardown list with the Node account', function (): void {
    app(ProjectLifecycleStepStore::class)->create($this->instance->project, LifecyclePhase::Teardown, new LifecycleStep('remove', 'private teardown program', 30), null, null);
    $admission = app(StartInstanceStepListAction::class);
    $this->operation->forceFill(['input' => ['instance_id' => $this->instance->id, 'source' => 'project', 'list' => 'teardown',
        'configuration_digest' => $admission->configurationDigest($this->instance->project, LifecyclePhase::Teardown), ...$admission->appStepAdmission($this->instance, LifecyclePhase::Teardown)]])->save();
    $intent = app(InstanceSetupProjection::class)->input($this->operation)['intent'];
    expect($intent['list'])->toBe('teardown')->and($intent['user'])->toBe($this->instance->node->user)
        ->and($intent['steps'][0]['command'])->toBe('private teardown program');
});

it('refuses a changed execution account or complete timeout after admission', function (string $change): void {
    if ($change === 'account') {
        $this->instance->node->update(['user' => 'changed-account']);
    } else {
        ProjectLifecycleStep::query()->where('project_id', $this->instance->project_id)->update(['timeout_seconds' => 31]);
    }
    expect(fn () => app(InstanceSetupProjection::class)->input($this->operation))->toThrow(ResourceOperationException::class);
})->with(['account', 'timeout']);

it('keeps a changed checkout state when an older list result settles', function (): void {
    $this->instance->update(['checkout_path' => '/srv/new-checkout', 'first_setup_pending' => true, 'error_code' => 'newer.setup_error']);
    app(InstanceSetupProjection::class)->apply($this->operation, ['run_id' => $this->operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => '2026-10-30T18:00:00.000Z', 'finished_at' => '2026-10-30T18:00:01.000Z',
        'result' => ['instance_id' => $this->instance->id, 'outcome' => 'succeeded', 'no_op' => false], 'error_code' => null]);
    expect($this->instance->fresh()->first_setup_pending)->toBeTrue()->and($this->instance->fresh()->error_code)->toBe('newer.setup_error');
});

it('refuses a development admission whose environment changes before execution', function (): void {
    $this->instance->node->roles()->where('role', RoleName::AppDev)->update(['role' => RoleName::AppProd]);
    expect(fn () => app(InstanceSetupProjection::class)->input($this->operation->fresh()))->toThrow(ResourceOperationException::class);
});
