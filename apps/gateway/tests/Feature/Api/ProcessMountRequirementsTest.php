<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessTarget;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'shared.scoped.fixture']);
    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'mount-contract', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->payload = ['target_type' => 'node', 'target_id' => $this->node->id, 'name' => 'mount-consumer',
        'runtime' => 'systemd', 'command' => ['/usr/bin/sleep', '60']];
});

it('retains mount requirements on an omitted update and clears them only explicitly', function (): void {
    $paths = ['/srv/state', '/srv/source'];
    $this->postJson('/api/v1/processes', [...$this->payload, 'required_mount_paths' => $paths])->assertAccepted();
    $process = Process::query()->sole();
    expect($process->runtime_config['required_mount_paths'])->toBe($paths)
        ->and(Operation::query()->sole()->input['required_mount_paths'])->toBe($paths);
    $operation = Operation::query()->sole();
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$operation->state['swamp_run_id'])
        ->assertOk()->assertJsonPath('data.input.installation.required_mount_paths', $paths);
    Operation::query()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $process->update(['status' => 'active']);
    $this->patchJson('/api/v1/processes/'.$process->id, ['keep_alive' => true])->assertAccepted();
    expect($process->refresh()->runtime_config['required_mount_paths'])->toBe($paths);
    Operation::query()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $process->update(['status' => 'active']);
    $this->patchJson('/api/v1/processes/'.$process->id, ['required_mount_paths' => []])->assertAccepted();
    expect($process->refresh()->runtime_config['required_mount_paths'] ?? [])->toBe([]);
});

it('rejects malformed mount requirements before creating intent', function (mixed $paths): void {
    $this->postJson('/api/v1/processes', [...$this->payload, 'required_mount_paths' => $paths])->assertUnprocessable();
    expect(Process::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
})->with([
    'null' => [null], 'scalar' => ['/srv/state'], 'relative' => [['srv/state']],
    'parent' => [['/srv/../state']], 'dot' => [['/srv/./state']], 'repeated slash' => [['/srv//state']],
    'trailing slash' => [['/srv/state/']], 'control' => [["/srv/state\nAfter=evil.service"]],
    'trailing control' => [["/srv/state\n"]], 'leading whitespace' => [[' /srv/state']],
    'duplicate' => [['/srv/state', '/srv/state']], 'oversized list' => [array_map(fn ($n) => '/srv/state'.$n, range(1, 65))],
    'UTF8 byte limit' => [['/'.str_repeat('é', 2048)]],
]);

it('rejects Docker mount requirements without discarding a stored systemd dependency', function (): void {
    $this->postJson('/api/v1/processes', [...$this->payload, 'runtime' => 'docker', 'image' => 'example:1', 'required_mount_paths' => ['/srv/state']])->assertUnprocessable();
    $this->postJson('/api/v1/processes', [...$this->payload, 'required_mount_paths' => ['/srv/state']])->assertAccepted();
    $process = Process::query()->sole();
    Operation::query()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $process->update(['status' => 'active']);
    $this->patchJson('/api/v1/processes/'.$process->id, ['runtime' => 'docker', 'image' => 'example:1'])->assertUnprocessable();
    expect($process->refresh()->runtime_config['required_mount_paths'])->toBe(['/srv/state']);
});

it('preserves literal trailing whitespace through create and update', function (): void {
    $paths = ['/srv/state ', '/srv/literal%name'];
    $this->postJson('/api/v1/processes', [...$this->payload, 'required_mount_paths' => $paths])->assertAccepted();
    $process = Process::query()->sole();
    expect($process->runtime_config['required_mount_paths'])->toBe($paths);
    Operation::query()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $process->update(['status' => 'active']);
    $this->patchJson('/api/v1/processes/'.$process->id, ['required_mount_paths' => ["/srv/state\n"]])->assertUnprocessable();
    expect($process->refresh()->runtime_config['required_mount_paths'])->toBe($paths);
    $this->patchJson('/api/v1/processes/'.$process->id, ['required_mount_paths' => ['/srv/new ']])->assertAccepted();
    expect($process->refresh()->runtime_config['required_mount_paths'])->toBe(['/srv/new ']);
});

it('keeps required paths when updating a Process with an explicit service account', function (): void {
    $agents = new FakeAgentCommandRunner;
    $agents->answer('account.lookup', ['user' => 'nobody', 'uid' => 65534, 'home' => '/nonexistent', 'group' => 'nogroup', 'shell' => '/usr/sbin/nologin']);
    app()->instance(AgentCommandRunner::class, $agents);
    $paths = ['/srv/state'];
    $this->postJson('/api/v1/processes', [...$this->payload, 'user' => 'nobody', 'required_mount_paths' => $paths])->assertAccepted();
    $process = Process::query()->sole();
    Operation::query()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $process->update(['status' => 'active']);
    $this->patchJson('/api/v1/processes/'.$process->id, ['keep_alive' => true])->assertAccepted();
    expect($process->refresh()->runtime_config['required_mount_paths'])->toBe($paths)
        ->and($process->runtime_config['user'])->toBe('nobody');
});

it('accepts an explicitly empty Docker mount dependency list', function (): void {
    $this->postJson('/api/v1/processes', [...$this->payload, 'runtime' => 'docker', 'image' => 'example:1', 'required_mount_paths' => []])->assertAccepted();
    expect(Process::query()->sole()->runtime_config)->not->toHaveKey('required_mount_paths');
});

it('requires JSON for explicit literal mount paths', function (): void {
    $this->post('/api/v1/processes', [...$this->payload, 'required_mount_paths' => ["/srv/state\n"]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('required_mount_paths', 'error.details');
    expect(Process::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
});

it('uses the same literal mount contract through MCP', function (): void {
    $paths = ['/srv/state '];
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'process-create', 'arguments' => [...$this->payload, 'required_mount_paths' => $paths]]])
        ->assertOk()->assertJsonPath('result.isError', false);
    expect(Process::query()->sole()->runtime_config['required_mount_paths'])->toBe($paths);
});

it('renders mount paths literally without systemd specifier or directive injection', function (): void {
    $process = new Process(['name' => 'mount-consumer', 'working_directory' => '/srv/app', 'restart_policy' => 'never',
        'runtime_config' => ['command' => ['/usr/bin/sleep', '60'], 'required_mount_paths' => ['/srv/state with space', '/srv/%n"$literal']]]);
    $process->id = 7;
    $target = new ProcessTarget(node: $this->node, user: 'orbit', checkoutPath: '/srv/app');
    $unit = new SystemdProcessRenderer()->render($process, $target);
    expect($unit)->toContain('RequiresMountsFor=/srv/state\x20with\x20space /srv/%%n\x22\x24literal')
        ->and(substr_count($unit, 'RequiresMountsFor='))->toBe(1);
});

it('refuses explicit mount configuration on a stored preset, including an empty list', function (): void {
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'preset', 'runtime' => 'systemd',
        'runtime_config' => ['preset' => 'vp-dev', 'command' => ['/usr/bin/true']], 'status' => 'active', 'desired_state' => 'stopped']);
    $this->patchJson('/api/v1/processes/'.$process->id, ['required_mount_paths' => []])->assertUnprocessable()->assertJsonValidationErrors('required_mount_paths', 'error.details');
    expect(Operation::query()->count())->toBe(0);
});
