<?php

declare(strict_types=1);

use App\Actions\Analytics\AdmitAnalyticsUpdateAction;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsUpdateProjection;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\Nodes\RoleName;
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
    $this->node = Node::query()->create(['name' => 'services', 'user' => 'orbit', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3', 'platform' => 'linux', 'architecture' => 'x86_64', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIFIxtureAnalyticsRuntimeKey123456']);
    $this->node->accessibleNodes()->attach($gateway);
    $this->node->roles()->create(['role' => RoleName::Analytics, 'status' => LifecycleStatus::Active]);
    $this->environment = ['BASE_URL' => 'https://analytics.orbit', 'SECRET_KEY_BASE' => str_repeat('secret-runtime-fixture-', 4)];
    $this->process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'plausible',
        'runtime' => ProcessRuntime::Docker, 'desired_state' => DesiredProcessState::Running, 'status' => LifecycleStatus::Active,
        'restart_policy' => 'unless-stopped', 'runtime_config' => ['image' => 'ghcr.io/plausible/community-edition:v3.2.1',
            'command' => ['/usr/local/bin/orbit-agent', 'service', 'plausible'], 'environment' => $this->environment,
            'ports' => ['10.44.0.3:8000:8000/tcp'], 'volumes' => [['source' => '/usr/local/bin/orbit-agent', 'target' => '/usr/local/bin/orbit-agent', 'read_only' => true], ['source' => '/srv/plausible', 'target' => '/data', 'read_only' => false]]]]);
    $this->mock(AgentCommandRunner::class)->shouldNotReceive('run');
    config(['swamp.run_token' => 'private.start.fixture', 'orbit.analytics.plausible_version' => '3.2.1']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->socket->reply = function (array $message): array {
        if ($message['type'] === 'vault.put') {
            return [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        return [['type' => 'event', 'id' => $message['id'], 'event' => [
            'kind' => 'started', 'workflowName' => 'operation-analytics-update-direct', 'runId' => $this->runId]]];
    };
    app()->instance(WebSocketClient::class, $this->socket);
    $this->body = ['run_id' => $this->runId, 'started_at' => '2026-10-30T17:00:00.000Z'];
    $this->running = fn (Operation $operation): Operation => app(AnalyticsUpdateProjection::class)->apply($operation, [...$this->body, 'status' => 'running']);
});

it('admits the direct-only version intent without changing the pin or runtime', function (): void {
    $response = $this->postJson('/api/v1/analytics/update', ['version' => '3.3.0'])->assertAccepted()->assertJsonPath('data.kind', 'analytics.update');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->lock_key)->toBeNull()->and($operation->state)->not->toHaveKeys(['runtime_plan', 'executor_runs', 'executor_lease_node_id']);
    expect(app(AnalyticsRoleSettingsRepository::class)->version($this->node))->toBe('3.2.1')
        ->and($this->process->fresh()->runtime_config['image'])->toBe('ghcr.io/plausible/community-edition:v3.2.1');
    expect(json_encode($operation->toArray()))->not->toContain($this->environment['SECRET_KEY_BASE']);
    expect($this->socket->sent)->toHaveCount(2);
});

it('freezes the safe runtime plan at admission without exposing environment values', function (): void {
    $operation = app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0');
    $native = app(AnalyticsUpdateProjection::class)->input($operation);
    $input = $native['intent'];
    expect($input['after']['image'])->toBe('ghcr.io/plausible/community-edition:v3.3.0')
        ->and($input)->not->toHaveKey('agent_sha256')
        ->and($input['after']['command'])->toBe(PlausibleProcess::Startup)
        ->and($input['after']['mounts'])->toBe([['source' => '/srv/plausible', 'target' => '/data', 'read_only' => false]])
        ->and($input['before']['process_id'])->toBe($this->process->id)
        ->and($input['after']['container'])->toBe($input['before']['container'])
        ->and($input['health_url'])->toBe('http://10.44.0.3:8000/api/health')
        ->and($operation->state)->not->toHaveKey('runtime_plan');
    expect(json_encode($input))->not->toContain($this->environment['SECRET_KEY_BASE'])->not->toContain('"environment":');
});

it('projects the exact version only after success and preserves the existing environment and Process identity', function (): void {
    $operation = ($this->running)(app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0'));
    $body = [...$this->body, 'status' => 'succeeded', 'finished_at' => '2026-10-30T17:00:01.000Z',
        'result' => ['ready' => true, 'version' => '3.3.0', 'previous_version' => '3.2.1']];
    app(AnalyticsUpdateProjection::class)->apply($operation, $body);
    expect($this->process->fresh()->runtime_config['environment'])->toBe($this->environment)
        ->and($this->process->fresh()->runtime_config['command'])->toBe(PlausibleProcess::Startup)
        ->and($this->process->fresh()->runtime_config['volumes'])->toBe([['source' => '/srv/plausible', 'target' => '/data', 'read_only' => false]])
        ->and(Process::query()->count())->toBe(1)
        ->and(app(AnalyticsRoleSettingsRepository::class)->version($this->node))->toBe('3.3.0');
    app(AnalyticsRoleSettingsRepository::class)->storeVersion($this->node, '3.4.0');
    app(AnalyticsUpdateProjection::class)->apply($operation->fresh(), $body);
    expect(app(AnalyticsRoleSettingsRepository::class)->version($this->node))->toBe('3.4.0');
});

it('projects native success with bounded admitted fields and no runtime proof', function (): void {
    $operation = app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0');
    $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'environment' => [...$this->environment, 'EXTRA' => 'newer']]]);

    $projected = app(AnalyticsUpdateProjection::class)->apply($operation, [...$this->body,
        'status' => 'succeeded', 'finished_at' => '2026-10-30T17:00:01.000Z',
        'result' => ['environment' => 'secret.fixture', 'version' => '9.9.9']]);

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['ready' => true, 'version' => '3.3.0', 'previous_version' => '3.2.1']);
    expect($this->process->fresh()->runtime_config['image'])->toBe('ghcr.io/plausible/community-edition:v3.3.0')
        ->and($this->process->fresh()->runtime_config['environment']['EXTRA'])->toBe('newer');
});

it('records a native failure before the action without inventing runtime recovery', function (): void {
    $operation = app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0');

    $projected = app(AnalyticsUpdateProjection::class)->apply($operation, [...$this->body,
        'status' => 'failed', 'finished_at' => '2026-10-30T17:00:01.000Z', 'error_code' => 'workflow.failed']);

    expect($projected->result)->toBe(['error_code' => 'workflow.failed']);
    expect($this->process->fresh()->status)->toBe(LifecycleStatus::Active)
        ->and(app(AnalyticsRoleSettingsRepository::class)->version($this->node))->toBe('3.2.1');
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

it('refuses a stopped Plausible before recording or starting an update', function (): void {
    $this->process->update(['desired_state' => DesiredProcessState::Stopped]);

    $this->postJson('/api/v1/analytics/update', ['version' => '3.3.0'])->assertConflict()
        ->assertJsonPath('error.code', 'analytics.runtime_stopped');

    expect(Operation::query()->count())->toBe(0)
        ->and($this->socket->sent)->toBe([]);
});

it('captures the environment only through the shared native vault copy', function (): void {
    $operation = app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0');
    $input = app(AnalyticsUpdateProjection::class)->input($operation);
    expect($input['execution_secrets']['environment'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'process-create-operation-'.$operation->id]);
    expect($input['intent']['ssh_execution']['control_node_id'])->toBe(209);
    expect($input['intent']['ssh_execution']['target']['host_keys'])->toBe(['ssh-ed25519 '.$this->node->ssh_host_key]);
    $put = $this->socket->sent[0];
    expect($put['type'])->toBe('vault.put')->and(json_decode($put['payload']['value'], true))->toBe($this->environment);
    expect($this->socket->sent[1]['payload']['inputs'])->toMatchArray($input);
    expect(json_encode([$operation->fresh()->toArray(), Activity::query()->get()->toArray(), $this->socket->sent[1]]))->not->toContain($this->environment['SECRET_KEY_BASE'], '$secret', 'runtime_environment');
});

it('refuses a missing SSH pin before recording or starting an update', function (): void {
    $this->node->update(['ssh_host_key' => null]);
    expect(fn () => app(AdmitAnalyticsUpdateAction::class)->execute('3.3.0'))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});
