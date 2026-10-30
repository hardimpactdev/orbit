<?php

declare(strict_types=1);

use App\Actions\Analytics\AdmitAnalyticsUpdateAction;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsRuntimeEnvironmentSource;
use App\Domain\Analytics\AnalyticsUpdateProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1']);
    $this->gateway = $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->node = Node::query()->create(['name' => 'services', 'user' => 'orbit', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3', 'platform' => 'linux', 'architecture' => 'x86_64']);
    $this->node->accessibleNodes()->attach($gateway);
    $this->node->roles()->create(['role' => RoleName::Analytics, 'status' => LifecycleStatus::Active]);
    $this->environment = ['BASE_URL' => 'https://analytics.orbit', 'SECRET_KEY_BASE' => str_repeat('secret-runtime-fixture-', 4)];
    $this->process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'plausible',
        'runtime' => ProcessRuntime::Docker, 'desired_state' => DesiredProcessState::Running, 'status' => LifecycleStatus::Active,
        'restart_policy' => 'unless-stopped', 'runtime_config' => ['image' => 'ghcr.io/plausible/community-edition:v3.2.1',
            'command' => ['/usr/local/bin/orbit-agent', 'service', 'plausible'], 'environment' => $this->environment,
            'ports' => ['10.44.0.3:8000:8000/tcp'], 'volumes' => []]]);
    $this->mock(AgentCommandRunner::class)->shouldNotReceive('run');
    config(['swamp.run_token' => 'private.start.fixture', 'orbit.analytics.plausible_version' => '3.2.1']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-analytics-update-direct', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $this->socket);
    $this->body = ['run_id' => $this->runId, 'started_at' => '2026-10-30T17:00:00.000Z'];
    $this->running = fn (Operation $operation): Operation => app(AnalyticsUpdateProjection::class)->apply($operation, [...$this->body, 'status' => 'running']);
});

it('admits the direct-only version intent without changing the pin or runtime', function (): void {
    $response = $this->postJson('/api/v1/analytics/update', ['version' => '3.3.0'])->assertAccepted()->assertJsonPath('data.kind', 'analytics.update');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect(app(OperationKinds::class)->directOnly('analytics.update'))->toBeTrue()
        ->and(app(DirectOperation::class)->writer('analytics.update'))->toBe('direct')
        ->and($operation->lock_key)->toBeNull()->and($operation->state)->not->toHaveKeys(['runtime_plan', 'executor_runs', 'executor_lease_node_id']);
    expect(app(AnalyticsRoleSettingsRepository::class)->version($this->node))->toBe('3.2.1')
        ->and($this->process->fresh()->runtime_config['image'])->toBe('ghcr.io/plausible/community-edition:v3.2.1');
    expect(json_encode($operation->toArray()))->not->toContain($this->environment['SECRET_KEY_BASE']);
    expect($this->socket->sent)->toHaveCount(1);
});

it('freezes safe runtime intent after native scope acquisition and never returns environment values', function (): void {
    $operation = ($this->running)(app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0'));
    $input = app(AnalyticsUpdateProjection::class)->input($operation);
    expect($input['after']['image'])->toBe('ghcr.io/plausible/community-edition:v3.3.0')
        ->and($input['before']['process_id'])->toBe($this->process->id)
        ->and($input['after']['container'])->toBe($input['before']['container']);
    expect(json_encode($input))->not->toContain($this->environment['SECRET_KEY_BASE'])->not->toContain('"environment":');
});

it('projects the exact version only after success and preserves the existing environment and Process identity', function (): void {
    $operation = ($this->running)(app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0'));
    $body = [...$this->body, 'status' => 'succeeded', 'finished_at' => '2026-10-30T17:00:01.000Z',
        'result' => ['ready' => true, 'version' => '3.3.0', 'previous_version' => '3.2.1']];
    app(AnalyticsUpdateProjection::class)->apply($operation, $body);
    expect($this->process->fresh()->runtime_config['environment'])->toBe($this->environment)
        ->and(Process::query()->count())->toBe(1)
        ->and(app(AnalyticsRoleSettingsRepository::class)->version($this->node))->toBe('3.3.0');
    app(AnalyticsRoleSettingsRepository::class)->storeVersion($this->node, '3.4.0');
    app(AnalyticsUpdateProjection::class)->apply($operation->fresh(), $body);
    expect(app(AnalyticsRoleSettingsRepository::class)->version($this->node))->toBe('3.4.0');
});

it('rejects invented success and secret-bearing result fields', function (): void {
    $operation = ($this->running)(app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0'));
    foreach ([['ready' => true, 'version' => '9.9.9', 'previous_version' => '3.2.1'], ['environment' => 'secret.fixture']] as $result) {
        expect(fn () => app(AnalyticsUpdateProjection::class)->apply($operation->fresh(), [...$this->body,
            'status' => 'succeeded', 'finished_at' => '2026-10-30T17:00:01.000Z', 'result' => $result]))->toThrow(ResourceOperationException::class);
    }
});

it('keeps the pin on failed updates and records failed runtime recovery', function (bool $restored): void {
    $operation = ($this->running)(app(AdmitAnalyticsUpdateAction::class)->execute('9.9.9'));
    $code = $restored ? 'analytics.update_failed' : 'analytics.rollback_failed';
    app(AnalyticsUpdateProjection::class)->apply($operation, [...$this->body, 'status' => 'failed',
        'finished_at' => '2026-10-30T17:00:01.000Z', 'error_code' => $code, 'result' => ['error_code' => $code, 'rolled_back' => $restored]]);
    expect(app(AnalyticsRoleSettingsRepository::class)->version($this->node))->toBe('3.2.1')
        ->and($this->process->fresh()->status)->toBe($restored ? LifecycleStatus::Active : LifecycleStatus::Failed);
})->with([true, false]);

it('validates the version before admitting native work', function (mixed $version): void {
    $this->postJson('/api/v1/analytics/update', ['version' => $version])->assertUnprocessable();
    expect($this->socket->sent)->toBe([]);
})->with(['v3.3.0', 'latest', '3.3', '3.3.0-rc1', '3.3.0; bad', '', null]);

it('refuses an absent role or runtime before admission', function (bool $roleMissing): void {
    if ($roleMissing) {
        $this->node->roles()->delete();
    } else {
        $this->process->delete();
    }
    $this->postJson('/api/v1/analytics/update', ['version' => '3.3.0'])->assertConflict()
        ->assertJsonPath('error.code', $roleMissing ? 'analytics.role_missing' : 'analytics.runtime_missing');
})->with([true, false]);

it('refuses a stale success before overwriting a later Process definition', function (): void {
    $operation = ($this->running)(app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0'));
    $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'environment' => [...$this->environment, 'EXTRA' => 'newer']]]);
    expect(fn () => app(AnalyticsUpdateProjection::class)->apply($operation, [...$this->body, 'status' => 'succeeded',
        'finished_at' => '2026-10-30T17:00:01.000Z', 'result' => ['ready' => true, 'version' => '3.3.0', 'previous_version' => '3.2.1']]))->toThrow(ResourceOperationException::class);
    expect($operation->fresh()->status->value)->toBe('running')
        ->and($this->process->fresh()->runtime_config['environment']['EXTRA'])->toBe('newer')
        ->and(app(AnalyticsRoleSettingsRepository::class)->version($this->node))->toBe('3.2.1');
});

it('refuses a stopped Plausible before freezing a running update', function (): void {
    $operation = app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0');
    $this->process->update(['desired_state' => DesiredProcessState::Stopped]);
    expect(fn () => ($this->running)($operation))->toThrow(ResourceOperationException::class);
    expect($operation->fresh()->state)->not->toHaveKey('runtime_plan')
        ->and($this->process->fresh()->desired_state)->toBe(DesiredProcessState::Stopped);
});

it('reads the admitted environment privately without persisting it or declaring secret outputs', function (): void {
    $operation = ($this->running)(app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0'));
    $before = $operation->fresh()->getAttributes();
    config(['operation_executor.worker_node_ids' => [$this->node->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.runtime_environment', $this->environment);
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertDontSee($this->environment['SECRET_KEY_BASE']);
    expect($operation->fresh()->getAttributes())->toBe($before);
    expect(json_encode([$operation->fresh()->toArray(), $operation->steps()->get()->toArray(), Activity::query()->get()->toArray()]))->not->toContain($this->environment['SECRET_KEY_BASE']);
    $this->postJson($path.'/secret-output', ['run_id' => $this->runId, 'outputs' => ['environment' => 'invented']])->assertUnprocessable();
});

it('refuses stale private runtime ownership or environment', function (string $change): void {
    $operation = ($this->running)(app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0'));
    match ($change) {
        'environment' => $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'environment' => ['SECRET_KEY_BASE' => 'newer-private-fixture']]]),
        'process-owner' => $this->process->update(['owner_id' => $this->gateway->id]),
        'assignment' => $this->node->roles()->delete(),
        'stopped' => $this->process->update(['desired_state' => DesiredProcessState::Stopped]),
    };
    config(['operation_executor.worker_node_ids' => [$this->node->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$this->runId)
        ->assertConflict()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('error.code', 'analytics.runtime_changed')
        ->assertDontSee($this->environment['SECRET_KEY_BASE'])->assertDontSee('newer-private-fixture');
})->with(['environment', 'process-owner', 'assignment', 'stopped']);

it('keeps private reads bound to the exact Node run and unfinished Operation', function (): void {
    $operation = ($this->running)(app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0'));
    config(['dependency_detection.services_node_id' => $this->gateway->id, 'operation_executor.worker_node_ids' => [$this->node->id, $this->gateway->id]]);
    $path = '/api/v1/direct-operations/'.$operation->id.'/private-material?run_id=';
    $this->getJson($path.$this->runId)->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson($path.'20e8138a-cf7f-4c6c-85fd-ff561b575ac8')->assertConflict()->assertJsonPath('error.code', 'operation.run_mismatch');
    $operation->update(['status' => OperationStatus::Failed]);
    $this->getJson($path.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.finished');
});

it('rejects caller-selected private owners paths and command keys', function (): void {
    $operation = ($this->running)(app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0'));
    $source = app(AnalyticsRuntimeEnvironmentSource::class);
    foreach ([[$this->gateway->id, '', 'runtime_environment'], [$this->node->id, 'environment.write', 'runtime_environment'], [$this->node->id, '', 'foreign']] as [$id, $key, $parameter]) {
        expect(fn () => $source->resolve($operation, $id, $key, $parameter))->toThrow(ResourceOperationException::class);
    }
    $operation->update(['input' => [...$operation->input, 'runtime_environment' => ['$secret' => 'analytics.runtime_environment', 'id' => $this->gateway->id]]]);
    config(['operation_executor.worker_node_ids' => [$this->node->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$this->runId)->assertUnprocessable();
});
