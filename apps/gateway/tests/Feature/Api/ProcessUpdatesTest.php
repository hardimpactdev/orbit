<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Http\Mcp\ToolManifest;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'process-updates', 'status' => 'active', 'platform' => 'linux',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3',
    ]));
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
        ->and(new DockerProcessRenderer()->createArguments($process, $target))->not->toContain('--workdir');
    $process->update(['working_directory' => '/app']);
    $this->getJson('/api/v1/processes')->assertOk()->assertJsonPath('data.0.working_directory', '/app');
    expect(new DockerProcessRenderer()->createArguments($process->refresh(), $target))->toContain('--workdir', '/app');
});

it('replaces a stopped Process in place and clears omitted Docker fields', function (): void {
    $this->postJson('/api/v1/processes', [...$this->payload, 'working_directory' => '/app', 'environment' => ['OLD' => 'secret']])->assertCreated();
    $process = Process::query()->sole();
    $this->patchJson('/api/v1/processes/'.$process->id, [
        'runtime' => 'docker', 'image' => 'valkey/valkey:9', 'command' => ['valkey-server'], 'working_directory' => null,
    ])->assertOk()->assertJsonPath('data.id', $process->id)->assertJsonPath('data.name', 'cache')
        ->assertJsonPath('data.desired_state', 'stopped')->assertJsonPath('data.working_directory', null);
    expect($process->refresh()->runtime_config)->toMatchArray(['environment' => [], 'ports' => [], 'volumes' => [], 'image' => 'valkey/valkey:9'])
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
