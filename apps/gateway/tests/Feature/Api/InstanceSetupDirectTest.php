<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Instances\StepLists\InstanceSetupProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\ProjectLifecycleStep;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->instance = dev935_instance();
    $user = 'orbit-app-'.$this->instance->project_id;
    $this->instance->update(['production_user' => $user, 'production_home' => '/home/'.$user, 'checkout_path' => '/home/'.$user.'/releases/admitted']);
    app(ProjectLifecycleStepStore::class)->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('install', 'private setup program', 30), null, null);
    $admission = app(StartInstanceStepListAction::class);
    $this->operation = Operation::query()->create(['kind' => 'instance.run_steps', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->instance->node_id, 'status' => OperationStatus::Running,
        'input' => ['instance_id' => $this->instance->id, 'source' => 'project', 'list' => 'setup',
            'configuration_digest' => $admission->configurationDigest($this->instance->project, LifecyclePhase::Setup), ...$admission->appStepAdmission($this->instance, LifecyclePhase::Setup)],
        'state' => ['instance_step_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
});

it('selects direct for the complete kind and refuses the deleted polled writer', function (): void {
    $direct = app(DirectOperation::class);
    expect($direct->writer('instance.run_steps', ['list' => 'setup']))->toBe('direct')
        ->and($direct->writer('instance.run_steps', ['list' => 'teardown']))->toBe('direct');
    config(['swamp.direct_writers' => ['instance.run_steps' => 'polled']]);
    expect(fn () => $direct->writer('instance.run_steps'))->toThrow(ResourceOperationException::class);
});

it('returns only bound target release and command digests', function (): void {
    $input = app(InstanceSetupProjection::class)->input($this->operation);
    expect($input)->toBe(['instance_id' => $this->instance->id, 'list' => 'setup', 'project_id' => $this->instance->project_id, 'execution' => app(StartInstanceStepListAction::class)->appStepAdmission($this->instance, LifecyclePhase::Setup)['execution'],
        'steps' => [['name' => 'install', 'command_digest' => hash('sha256', 'private setup program'), 'timeout_seconds' => 30]]])
        ->and(json_encode($input))->not->toContain('private setup program');
});

it('refuses a moved target and changed release or setup after admission', function (string $change): void {
    match ($change) {
        'node' => $this->operation->update(['node_id' => Node::query()->create(['name' => 'other-app-node', 'public_ssh_host' => '192.0.2.111', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active'])->id]),
        'release' => $this->instance->update(['checkout_path' => $this->instance->production_home.'/releases/changed']),
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

it('keeps terminal read and replay available after current setup changes', function (): void {
    $gateway = Node::query()->create(['name' => 'setup-gateway', 'public_ssh_host' => '192.0.2.112', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.111', 'status' => 'active']);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => 'active']);
    $this->instance->node->accessibleNodes()->attach($gateway);
    config(['operation_executor.worker_node_ids' => [$this->instance->node_id], 'dependency_detection.services_node_id' => null]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->instance->node->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$this->operation->id;
    $body = ['run_id' => $this->operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => '2026-10-30T18:00:00.000Z', 'finished_at' => '2026-10-30T18:00:01.000Z',
        'result' => ['instance_id' => $this->instance->id, 'outcome' => 'succeeded', 'no_op' => false], 'error_code' => null];
    $this->postJson($path.'/result', $body)->assertOk();
    ProjectLifecycleStep::query()->where('project_id', $this->instance->project_id)->update(['command' => 'changed after completion']);
    $this->instance->update(['checkout_path' => '/changed/after/completion']);
    $this->getJson($path.'?run_id='.$body['run_id'])->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.input.execution.directory', $this->instance->production_home.'/releases/admitted');
    $this->postJson($path.'/result', $body)->assertOk();
});

it('projects an app setup failure to the Instance', function (): void {
    $this->instance->update(['first_setup_pending' => true]);
    app(InstanceSetupProjection::class)->apply($this->operation, [
        'run_id' => $this->operation->state['swamp_run_id'], 'status' => 'failed', 'started_at' => now()->subSecond()->toISOString(), 'finished_at' => now()->toISOString(),
        'result' => ['instance_id' => $this->instance->id, 'outcome' => 'failed', 'error_code' => 'instance.setup_step_failed', 'failed_step' => 'setup.install'], 'error_code' => 'instance.setup_step_failed',
    ]);
    expect($this->instance->fresh()->first_setup_pending)->toBeTrue()->and($this->instance->fresh()->failed_step)->toBe('setup')->and($this->instance->fresh()->error_code)->toBe('instance.setup_step_failed');
});

it('refuses the first unbound read and admits it only after the matching Gateway start response', function (): void {
    $gateway = Node::query()->create(['name' => 'setup-binding-gateway', 'public_ssh_host' => '192.0.2.113', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.113', 'status' => 'active']);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => 'active']);
    $this->instance->node->accessibleNodes()->attach($gateway);
    config(['operation_executor.worker_node_ids' => [$this->instance->node_id], 'dependency_detection.services_node_id' => null, 'swamp.run_token' => 'fixture.shared.run.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->instance->node->wireguard_ip]);
    $runId = $this->operation->state['swamp_run_id'];
    $this->operation->update(['state' => ['instance_step_writer' => 'direct']]);
    $path = '/api/v1/direct-operations/'.$this->operation->id.'?run_id='.$runId;
    $this->getJson($path)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-setup-direct', 'runId' => $runId]]];
    app()->instance(WebSocketClient::class, $socket);
    app(StartSwampRun::class)->start($this->operation);
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe($runId);
    $this->getJson($path)->assertOk()->assertJsonPath('data.input.execution.directory', $this->instance->production_home.'/releases/admitted');
});

it('supports teardown and lifecycle parent requests with immutable Node-account input', function (): void {
    app(ProjectLifecycleStepStore::class)->create($this->instance->project, LifecyclePhase::Teardown, new LifecycleStep('remove', 'private teardown program', 30), null, null);
    $parent = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->instance->node_id, 'status' => 'running', 'input' => []]);
    $admission = app(StartInstanceStepListAction::class);
    $this->operation->forceFill(['parent_operation_id' => $parent->id, 'input' => ['instance_id' => $this->instance->id, 'source' => 'project', 'list' => 'teardown',
        'configuration_digest' => $admission->configurationDigest($this->instance->project, LifecyclePhase::Teardown), ...$admission->appStepAdmission($this->instance, LifecyclePhase::Teardown)]])->save();
    $input = app(InstanceSetupProjection::class)->input($this->operation);
    expect($input['list'])->toBe('teardown')->and($input['execution']['user'])->toBe($this->instance->node->user)
        ->and($input['steps'][0]['command_digest'])->toBe(hash('sha256', 'private teardown program'));
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
