<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Http\Mcp\ToolManifest;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'process-updates', 'status' => 'active', 'platform' => 'linux',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3',
    ]));
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->payload = [
        'target_type' => 'node', 'target_id' => $this->node->id, 'name' => 'cache',
        'runtime' => 'docker', 'image' => 'valkey/valkey:8', 'command' => ['valkey-server'],
    ];
});

it('uses the Docker image working directory without rewriting existing overrides', function (): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertCreated()->assertJsonPath('data.working_directory', null);
    $process = Process::query()->sole();
    $target = app(ProcessTargetResolver::class)->forProcess($process);
    expect($process->working_directory)->toBeNull()
        ->and(new DockerProcessRenderer()->containerInput($process, $target)['working_directory'])->toBeNull();
    $process->update(['working_directory' => '/app']);
    $this->getJson('/api/v1/processes')->assertOk()->assertJsonPath('data.0.working_directory', '/app');
    expect(new DockerProcessRenderer()->containerInput($process->refresh(), $target)['working_directory'])->toBe('/app');
});

it('updates a stopped Process in place and preserves omitted Docker fields', function (): void {
    $this->postJson('/api/v1/processes', [...$this->payload, 'working_directory' => '/app', 'environment' => ['OLD' => 'secret']])->assertCreated();
    $process = Process::query()->sole();
    $this->patchJson('/api/v1/processes/'.$process->id, [
        'runtime' => 'docker', 'image' => 'valkey/valkey:9', 'command' => ['valkey-server'], 'working_directory' => null,
    ])->assertOk()->assertJsonPath('data.id', $process->id)->assertJsonPath('data.name', 'cache')
        ->assertJsonPath('data.desired_state', 'stopped')->assertJsonPath('data.working_directory', null);
    expect($process->refresh()->runtime_config)->toMatchArray(['environment' => ['OLD' => 'secret'], 'ports' => [], 'volumes' => [], 'image' => 'valkey/valkey:9'])
        ->and(Process::query()->count())->toBe(1);
});

it('persists failed update intent and reconverges an identical retry', function (): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertCreated();
    $process = Process::query()->sole();
    $replacement = ['runtime' => 'docker', 'image' => 'valkey/valkey:9', 'command' => ['valkey-server']];
    $this->runtime->failConverge = true;
    $this->patchJson('/api/v1/processes/'.$process->id, $replacement)->assertStatus(502);
    expect($process->refresh()->runtime_config['image'])->toBe('valkey/valkey:9')
        ->and($process->status->value)->toBe('failed');
    $this->runtime->failConverge = false;
    $before = count($this->runtime->convergedProcessIds);
    $this->patchJson('/api/v1/processes/'.$process->id, $replacement)->assertOk();
    expect(count($this->runtime->convergedProcessIds))->toBe($before + 1)
        ->and($process->refresh()->status->value)->toBe('active');
});

it('recovers a runtime switch cleanup recorded before a crash', function (): void {
    $process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'switching',
        'runtime' => 'docker', 'working_directory' => null, 'runtime_config' => ['image' => 'redis:8-alpine', 'command' => ['redis-server']],
        'restart_policy' => 'never', 'desired_state' => 'stopped', 'status' => 'provisioning',
    ]);
    $process->retired_runtime = ProcessRuntime::Systemd;
    $process->save();
    $this->patchJson('/api/v1/processes/'.$process->id, ['runtime' => 'docker', 'image' => 'redis:8-alpine', 'command' => ['redis-server']])->assertOk();
    expect($process->refresh()->retired_runtime)->toBeNull()
        ->and($this->runtime->removed)->toContain($process->id)
        ->and($this->runtime->convergedProcessIds)->toContain($process->id);
});

it('refuses identity fields and invalid specifications before mutation', function (): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertCreated();
    $process = Process::query()->sole();
    foreach ([['name' => 'other'], ['target_id' => 99], ['preset' => 'annotator'], ['start' => true], ['command' => []]] as $invalid) {
        $this->patchJson('/api/v1/processes/'.$process->id, [
            'runtime' => 'docker', 'image' => 'valkey/valkey:9', 'command' => ['valkey-server'], ...$invalid,
        ])->assertUnprocessable();
        expect($process->refresh()->runtime_config['image'])->toBe('valkey/valkey:8');
    }
});

it('accepts the documented environment map through MCP and preserves literal string values', function (): void {
    $tool = collect(ToolManifest::default()->definitions())->first(fn ($definition): bool => $definition->name === 'process-create');
    expect($tool->inputSchema['properties']['environment']['type'])->toBe('object');
    $openapi = json_decode(file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($openapi['paths']['/api/v1/processes']['post']['requestBody']['content']['application/json']['schema']['properties']['environment']['type'])->toBe('object');
    $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'process-create', 'arguments' => [...$this->payload,
            'environment' => ['CACHE_HOST' => '10.44.0.20', 'CACHE_PORT' => '6379', 'EMPTY' => '', 'LITERAL' => ' a=b '],
        ]],
    ])->assertOk()->assertJsonPath('result.isError', false);
    $process = Process::query()->sole();
    expect($process->runtime_config['environment'])->toMatchArray(['EMPTY' => '', 'LITERAL' => ' a=b ']);
    $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => 'process-update', 'arguments' => [
            'process' => $process->id, 'runtime' => 'docker', 'image' => 'valkey/valkey:9', 'command' => ['valkey-server'],
            'environment' => ['CACHE_HOST' => '10.44.0.21'],
        ]],
    ])->assertOk()->assertJsonPath('result.isError', false);
    expect($process->refresh()->runtime_config['environment'])->toBe(['CACHE_HOST' => '10.44.0.21']);
});

it('defaults Process restarts on deploy and accepts an explicit opt-out in create and replacement', function (): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertCreated()->assertJsonPath('data.restart_on_deploy', true);
    $process = Process::query()->sole();
    $specification = ['runtime' => 'docker', 'image' => 'valkey/valkey:8', 'command' => ['valkey-server']];
    $this->patchJson('/api/v1/processes/'.$process->id, [...$specification, 'restart_on_deploy' => false])
        ->assertOk()->assertJsonPath('data.restart_on_deploy', false);
    expect($process->refresh()->restart_on_deploy)->toBeFalse();
    $this->getJson('/api/v1/processes')->assertOk()->assertJsonPath('data.0.restart_on_deploy', false);
    $this->patchJson('/api/v1/processes/'.$process->id, $specification)
        ->assertOk()->assertJsonPath('data.restart_on_deploy', false);
    $this->patchJson('/api/v1/processes/'.$process->id, ['restart_on_deploy' => true])
        ->assertOk()->assertJsonPath('data.restart_on_deploy', true);
    $this->postJson('/api/v1/processes', [...$this->payload, 'name' => 'opt-out', 'restart_on_deploy' => false])
        ->assertCreated()->assertJsonPath('data.restart_on_deploy', false);
});

it('rejects invalid restart-on-deploy input before changing a Process', function (): void {
    $this->postJson('/api/v1/processes', [...$this->payload, 'restart_on_deploy' => 'sometimes'])->assertUnprocessable();
    expect(Process::query()->count())->toBe(0);
    $this->postJson('/api/v1/processes', $this->payload)->assertCreated();
    $process = Process::query()->sole();
    $this->patchJson('/api/v1/processes/'.$process->id, [
        'runtime' => 'docker', 'image' => 'valkey/valkey:8', 'command' => ['valkey-server'], 'restart_on_deploy' => null,
    ])->assertUnprocessable();
    expect($process->refresh()->restart_on_deploy)->toBeTrue();
});

it('keeps every omitted field when only ports change', function (string $transport, bool $repeatSpecification): void {
    $this->postJson('/api/v1/processes', [...$this->payload,
        'working_directory' => '/data', 'restart_policy' => 'unless-stopped',
        'keep_alive' => true, 'restart_on_deploy' => false,
        'environment' => ['LABEL' => 'retained'], 'ports' => ['6379:6379'],
        'volumes' => [['source' => 'cache-data', 'target' => '/data', 'read_only' => false]],
    ])->assertCreated();
    $process = Process::query()->sole();
    $before = $process->only(['runtime', 'working_directory', 'restart_policy', 'keep_alive', 'restart_on_deploy']);
    $config = $process->runtime_config;
    $config['ports'] = ['6380:6379'];

    $patch = ['ports' => ['6380:6379'], ...($repeatSpecification ? [
        'runtime' => 'docker', 'image' => 'valkey/valkey:8', 'command' => ['valkey-server'],
    ] : [])];
    if ($transport === 'mcp') {
        $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'process-update', 'arguments' => ['process' => $process->id, ...$patch]],
        ])->assertOk()->assertJsonPath('result.isError', false);
    } else {
        $this->json($transport, '/api/v1/processes/'.$process->id, $patch)->assertOk();
    }

    expect($process->refresh()->only(array_keys($before)))->toBe($before)
        ->and($process->runtime_config)->toBe($config);
})->with([
    'PATCH ports only' => ['PATCH', false], 'PATCH repeated runtime fields' => ['PATCH', true],
    'MCP ports only' => ['mcp', false], 'MCP repeated runtime fields' => ['mcp', true],
]);

it('keeps the systemd user and working directory when only restart policy changes', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('account.lookup', ['user' => 'svc-worker', 'uid' => 1001, 'group' => 'svc-worker', 'home' => '/home/worker', 'shell' => '/bin/sh']);
    app()->instance(AgentCommandRunner::class, $agents);
    $process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'worker',
        'runtime' => 'systemd', 'working_directory' => '/srv/worker',
        'runtime_config' => ['command' => ['/usr/bin/sleep', '60'], 'user' => 'svc-worker'],
        'restart_policy' => 'always', 'keep_alive' => true, 'restart_on_deploy' => false,
        'desired_state' => 'stopped', 'status' => 'active',
    ]);

    $this->patchJson('/api/v1/processes/'.$process->id, ['restart_policy' => 'never'])
        ->assertOk()->assertJsonPath('data.user', 'svc-worker')->assertJsonPath('data.working_directory', '/srv/worker');

    expect($process->refresh()->restart_policy)->toBe('never')
        ->and($process->runtime_config['command'])->toBe(['/usr/bin/sleep', '60'])
        ->and($process->keep_alive)->toBeTrue()->and($process->restart_on_deploy)->toBeFalse();
});

it('applies explicit empty lists false values and a null working directory', function (): void {
    $this->postJson('/api/v1/processes', [...$this->payload,
        'working_directory' => '/data', 'restart_policy' => 'unless-stopped', 'keep_alive' => true,
        'environment' => ['LABEL' => 'retained'], 'ports' => ['6379:6379'],
        'volumes' => [['source' => 'cache-data', 'target' => '/data']],
    ])->assertCreated();
    $process = Process::query()->sole();

    $this->patchJson('/api/v1/processes/'.$process->id, [
        'environment' => (object) [], 'ports' => [], 'volumes' => [],
        'working_directory' => null, 'keep_alive' => false, 'restart_policy' => 'never', 'restart_on_deploy' => false,
    ])->assertOk()->assertJsonPath('data.working_directory', null);

    expect($process->refresh()->runtime_config)->toMatchArray(['environment' => [], 'ports' => [], 'volumes' => []])
        ->and($process->keep_alive)->toBeFalse()->and($process->restart_on_deploy)->toBeFalse()
        ->and($process->restart_policy)->toBe('never');
});

it('validates partial fields against the stored runtime before mutation', function (array $invalid): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertCreated();
    $process = Process::query()->sole();
    $before = count($this->runtime->convergedProcessIds);

    $this->patchJson('/api/v1/processes/'.$process->id, $invalid)->assertUnprocessable();

    expect(count($this->runtime->convergedProcessIds))->toBe($before)
        ->and($process->refresh()->runtime_config['image'])->toBe('valkey/valkey:8');
})->with([
    'empty command' => [['command' => []]], 'null command' => [['command' => null]],
    'null runtime' => [['runtime' => null]], 'null image' => [['image' => null]],
    'invalid ports' => [['ports' => ['70000:80']]], 'Docker user' => [['user' => 'svc-worker']],
]);

it('publishes optional Process update fields without create defaults in OpenAPI and MCP', function (): void {
    $openapi = json_decode(file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $body = $openapi['paths']['/api/v1/processes/{process}']['patch']['requestBody']['content']['application/json']['schema'];
    $tool = collect(ToolManifest::default()->definitions())->first(fn ($definition): bool => $definition->name === 'process-update');
    expect($body['required'] ?? [])->toBe([])
        ->and($tool->inputSchema['required'] ?? [])->toBe(['process']);
    foreach (['runtime', 'command', 'image', 'working_directory', 'restart_policy', 'keep_alive', 'environment', 'ports', 'volumes', 'user', 'restart_on_deploy'] as $field) {
        expect($body['properties'][$field])->not->toHaveKey('default');
        expect($tool->inputSchema['properties'][$field])->not->toHaveKey('default');
    }
});

it('refuses runtime switches that would discard omitted Docker settings', function (array $patch, string $field): void {
    $this->postJson('/api/v1/processes', [...$this->payload, 'command' => ['/bin/sleep', '60'],
        'ports' => ['6379:6379'], 'volumes' => [['source' => 'cache-data', 'target' => '/data']],
    ])->assertCreated();
    $process = Process::query()->sole();
    $before = $process->runtime_config;
    $converged = count($this->runtime->convergedProcessIds);

    $this->patchJson('/api/v1/processes/'.$process->id, ['runtime' => 'systemd', ...$patch])
        ->assertUnprocessable()->assertJsonPath('error.code', 'process.option_invalid')->assertJsonPath('error.details.field', $field);

    expect($process->refresh()->runtime)->toBe(ProcessRuntime::Docker)
        ->and($process->runtime_config)->toBe($before)
        ->and(count($this->runtime->convergedProcessIds))->toBe($converged)
        ->and($this->runtime->removed)->toBe([]);
})->with([
    'image' => [[], 'image'], 'ports' => [['image' => null], 'ports'],
    'volumes' => [['image' => null, 'ports' => []], 'volumes'],
]);

it('switches runtime when incompatible Docker fields are explicitly cleared', function (): void {
    $this->postJson('/api/v1/processes', [...$this->payload, 'command' => ['/bin/sleep', '60'],
        'restart_policy' => 'unless-stopped', 'keep_alive' => true, 'restart_on_deploy' => false,
        'working_directory' => '/data', 'ports' => ['6379:6379'],
        'volumes' => [['source' => 'cache-data', 'target' => '/data']],
    ])->assertCreated();
    $process = Process::query()->sole();

    $this->patchJson('/api/v1/processes/'.$process->id, [
        'runtime' => 'systemd', 'image' => null, 'ports' => [], 'volumes' => [],
    ])->assertOk()->assertJsonPath('data.runtime', 'systemd');

    expect($process->refresh()->runtime_config['command'])->toBe(['/bin/sleep', '60'])
        ->and($process->restart_policy)->toBe('unless-stopped')->and($process->keep_alive)->toBeTrue()
        ->and($process->restart_on_deploy)->toBeFalse()->and($process->working_directory)->toBe('/data');
});

it('refuses a runtime switch that would discard an omitted systemd user', function (): void {
    $process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'worker',
        'runtime' => 'systemd', 'working_directory' => '/srv/worker',
        'runtime_config' => ['command' => ['/bin/sleep', '60'], 'user' => 'svc-worker'],
        'restart_policy' => 'always', 'desired_state' => 'stopped', 'status' => 'active',
    ]);
    $before = count($this->runtime->convergedProcessIds);

    $this->patchJson('/api/v1/processes/'.$process->id, ['runtime' => 'docker', 'image' => 'alpine:3'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'process.option_invalid')->assertJsonPath('error.details.field', 'user');

    expect($process->refresh()->runtime)->toBe(ProcessRuntime::Systemd)
        ->and($process->runtime_config['user'])->toBe('svc-worker')
        ->and(count($this->runtime->convergedProcessIds))->toBe($before);
});
