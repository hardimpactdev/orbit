<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentCommandRunner;

function settingsOperationNode(string $name, string $ip): Node
{
    return Node::query()->create(['name' => $name, 'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.20', 'wireguard_ip' => $ip]);
}

function settingsAdmission($test, bool $appDev = true, ?string $root = '/srv/new'): array
{
    $gateway = settingsOperationNode('gateway', '10.44.0.1');
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node = settingsOperationNode('dev', '10.44.0.2');
    $node->update(['settings' => ['apps' => ['path' => '/srv/old'], 't3' => ['token' => 'unrelated-secret']]]);
    if ($appDev) {
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    }
    $resolver = Mockery::mock(ManagedUserAccountResolver::class);
    $resolver->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $resolver);
    $test->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->withHeader('Idempotency-Key', 'settings-proof');
    $response = $test->patchJson("/api/v1/nodes/{$node->id}/settings", ['apps' => $root === null ? null : ['path' => $root]])->assertAccepted();

    return [$node, $response->json('data.id'), $gateway];
}

function settingsExecutor($test, Node $gateway): void
{
    $services = settingsOperationNode('services', '10.44.0.3');
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $test->withoutHeader('Idempotency-Key')->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => 'node.settings', 'worker' => 'settings-1'])->assertOk();
}

function settingsRunInput(int $id): array
{
    $input = Operation::findOrFail($id)->input;

    return ['worker' => 'settings-1', 'key' => 'node-storage-root', 'transport' => 'agent',
        'name' => 'node.prepare-storage-root', 'input' => array_intersect_key($input, array_flip(['root', 'user', 'group', 'create_missing']))];
}

it('records only settings intent and replays admission without executing PHP', function (): void {
    Queue::fake();
    [$node, $id] = settingsAdmission($this);
    $this->patchJson("/api/v1/nodes/{$node->id}/settings", ['apps' => ['path' => '/srv/new']])
        ->assertAccepted()->assertJsonPath('data.id', $id);
    expect($node->refresh()->settings['apps']['path'])->toBe('/srv/old');
    expect(Operation::query()->count())->toBe(1);
    expect(json_encode(Operation::findOrFail($id)->input))->not->toContain('unrelated-secret');
    Queue::assertNothingPushed();
});

it('persists settings only after a proven check and preserves unrelated stored keys', function (bool $appDev): void {
    [$node, $id, $gateway] = settingsAdmission($this, $appDev);
    expect(Operation::findOrFail($id)->input['create_missing'])->toBe($appDev);
    settingsExecutor($this, $gateway);
    $runner = new FakeAgentCommandRunner;
    $runner->answer('node.prepare-storage-root', []);
    app()->instance(AgentCommandRunner::class, $runner);
    $path = "/api/v1/operation-executor/{$id}";
    $this->postJson($path.'/run', settingsRunInput($id))->assertOk();
    $this->postJson($path.'/run', settingsRunInput($id))->assertOk();
    $this->postJson($path.'/step', ['worker' => 'settings-1', 'key' => 'done', 'name' => 'node-storage-root',
        'status' => 'succeeded', 'result' => new stdClass])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'settings-1', 'status' => 'succeeded', 'result' => new stdClass])->assertOk();
    expect($runner->commands())->toBe(['node.prepare-storage-root'])
        ->and($runner->calls[0]['node'])->toBe($node->name);
    expect($node->refresh()->settings)->toBe(['apps' => ['path' => '/srv/new'], 't3' => ['token' => 'unrelated-secret']]);
})->with([true, false]);

it('does not store new settings after a refused or cancelled operation', function (string $status): void {
    [$node, $id, $gateway] = settingsAdmission($this);
    settingsExecutor($this, $gateway);
    $body = ['worker' => 'settings-1', 'status' => $status];
    if ($status === 'failed') {
        $body['error_code'] = 'node.settings_root_failed';
    }
    $this->postJson("/api/v1/operation-executor/{$id}/complete", $body)->assertOk();
    expect($node->refresh()->settings['apps']['path'])->toBe('/srv/old');
})->with(['failed', 'cancelled']);

it('refuses bare completion and fabricated successful checks', function (): void {
    [$node, $id, $gateway] = settingsAdmission($this);
    settingsExecutor($this, $gateway);
    $path = "/api/v1/operation-executor/{$id}";
    $this->postJson($path.'/complete', ['worker' => 'settings-1', 'status' => 'succeeded', 'result' => new stdClass])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($path.'/step', ['worker' => 'settings-1', 'key' => 'forged', 'name' => 'node-storage-root',
        'status' => 'succeeded', 'result' => new stdClass])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    expect($node->refresh()->settings['apps']['path'])->toBe('/srv/old');
});

it('binds execution to the admitted Node, root and command', function (string $case): void {
    [, $id, $gateway] = settingsAdmission($this);
    settingsExecutor($this, $gateway);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $data = settingsRunInput($id);
    $data = match ($case) {
        'target' => [...$data, 'node_id' => $gateway->id],
        'root' => [...$data, 'input' => [...$data['input'], 'root' => '/etc']],
        'command' => [...$data, 'name' => 'agent.remove'],
        'remote program' => [...$data, 'transport' => 'remote_program', 'name' => 'gateway.task-workspace-owner'],
        'type' => [...$data, 'input' => [...$data['input'], 'create_missing' => 'yes']],
        'extra field' => [...$data, 'input' => [...$data['input'], 'node_id' => $gateway->id]],
    };
    $this->postJson("/api/v1/operation-executor/{$id}/run", $data)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
})->with(['target', 'root', 'command', 'remote program', 'type', 'extra field']);

it('prepares the managed default before clearing the last apps override', function (): void {
    [$node, $id, $gateway] = settingsAdmission($this, root: null);
    expect(Operation::findOrFail($id)->input)->toMatchArray(['root' => '/home/orbit/apps', 'settings' => null, 'create_missing' => true]);
    settingsExecutor($this, $gateway);
    $runner = new FakeAgentCommandRunner;
    $runner->answer('node.prepare-storage-root', []);
    app()->instance(AgentCommandRunner::class, $runner);
    $path = "/api/v1/operation-executor/{$id}";
    $this->postJson($path.'/run', settingsRunInput($id))->assertOk();
    $this->postJson($path.'/step', ['worker' => 'settings-1', 'key' => 'done', 'name' => 'node-storage-root',
        'status' => 'succeeded', 'result' => new stdClass])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'settings-1', 'status' => 'succeeded', 'result' => new stdClass])->assertOk();
    expect($node->refresh()->settings)->toBe(['t3' => ['token' => 'unrelated-secret']]);
});

it('preserves a task VM settings patch made after apps-root admission', function (): void {
    [$node, $id, $gateway] = settingsAdmission($this);
    $this->withoutHeader('Idempotency-Key')->patchJson("/api/v1/nodes/{$node->id}/settings", [
        'task_vms' => ['cidr' => '10.251.77.0/24', 'max_vms' => 4],
    ])->assertOk();
    $host = $node->refresh()->settings['task_vms'];
    settingsExecutor($this, $gateway);
    $runner = new FakeAgentCommandRunner;
    $runner->answer('node.prepare-storage-root', []);
    app()->instance(AgentCommandRunner::class, $runner);
    $path = "/api/v1/operation-executor/{$id}";
    $this->postJson($path.'/run', settingsRunInput($id))->assertOk();
    $this->postJson($path.'/step', ['worker' => 'settings-1', 'key' => 'done', 'name' => 'node-storage-root',
        'status' => 'succeeded', 'result' => new stdClass])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'settings-1', 'status' => 'succeeded', 'result' => new stdClass])->assertOk();
    expect($node->refresh()->settings['task_vms'])->toBe($host)
        ->and($node->settings['apps']['path'])->toBe('/srv/new');
});
