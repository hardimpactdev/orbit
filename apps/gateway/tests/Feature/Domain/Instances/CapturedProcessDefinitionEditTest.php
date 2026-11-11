<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Instances\StartInstanceMigrateAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\InstanceMigration\InstanceMigrateProjection;
use App\Domain\InstanceMigration\MigratedProcessDefinitions;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessDefinition;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/ContainerInstanceHelpers.php';

it('deploys a keep-alive edit of a captured vp-dev definition to the default and lets a new clone inherit it', function (bool $historical): void {
    Queue::fake();
    Http::preventStrayRequests();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209',
        'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ExtensionStore::class)->set('conn', true);
    $this->mock(TaskVmModelRoute::class, function ($mock): void {
        $mock->shouldReceive('assertReady')->andReturnNull();
        $mock->shouldReceive('origin')->andReturn('http://10.44.0.3:8318');
        $mock->shouldReceive('key')->andReturn('fixture-model-key');
    });
    $project = containerDefaultProject();
    $node = containerDefaultNode();
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/home/orbit/apps/tool/default', 'branch' => 'main', 'status' => InstanceState::Active,
        'first_setup_pending' => false, 'vite_port' => 5195]);
    $process = Process::query()->forceCreate(['owner_type' => 'instance', 'owner_id' => $instance->id, 'name' => 'vp-dev',
        'runtime' => 'systemd', 'working_directory' => $instance->checkout_path, 'status' => LifecycleStatus::Active,
        'desired_state' => 'running', 'restart_policy' => 'on-failure', 'keep_alive' => false, 'restart_on_deploy' => false,
        'runtime_config' => ['preset' => 'vp-dev',
            'command' => ['/usr/local/bin/vp', 'dev', '--host=127.0.0.1', '--port=${ORBIT_DEV_SERVER_PORT}', '--strictPort', '--base=/__orbit/vite/'],
            'environment_file' => $instance->checkout_path.'/.env']]);
    $migration = app(StartInstanceMigrateAction::class)->execute($instance, null, null);
    $migration->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'capture-edit']])->save();
    app(InstanceMigrateProjection::class)->apply($migration, ['status' => 'succeeded', 'run_id' => 'capture-edit',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $instance->id, 'ready' => true, 'moved' => true, 'restored' => false, 'commit' => str_repeat('d', 40)]]);
    $freshSpec = ProcessDefinition::query()->sole()->spec;
    if ($historical) {
        $process->refresh()->update(['source_definition_id' => null]);
        ProcessDefinition::query()->delete();
        app(MigratedProcessDefinitions::class)->repair();
    }
    $definition = ProcessDefinition::query()->sole();
    expect($definition->spec)->toBe($freshSpec);
    expect($definition->spec)->not->toHaveKey('preset_version');
    $node->accessibleNodes()->attach($node->id);

    foreach ([['command' => ['vp', 'custom']], ['preset' => null]] as $invalid) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
            "/api/v1/projects/{$project->id}/process-definitions/vp-dev",
            ['name' => 'vp-dev', 'environments' => ['development'], 'spec' => [...$definition->spec, ...$invalid]],
        );
        $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect($response->json('error.details')['spec.preset'])->toContain(
            'Only an existing captured vp-dev definition with its canonical command and preset metadata can be edited.',
        );
        expect($definition->refresh()->spec)->toBe($freshSpec);
    }

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->putJson(
        "/api/v1/projects/{$project->id}/process-definitions/vp-dev",
        ['name' => 'vp-dev', 'environments' => ['development'], 'spec' => ['keep_alive' => true]],
    )->assertOk()->assertJsonPath('data.spec.keep_alive', true);

    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn([
        'head' => str_repeat('e', 40), 'files' => ['composer.json' => null, '.env.example' => null, 'artisan' => null],
    ]);
    expect($process->refresh()->keep_alive)->toBeFalse();
    $deploy = app(StartInstanceDeployAction::class)->execute($instance->refresh(), 'test', null);
    expect($deploy->input['deployment']['variant'])->toBe('container');
    expect($process->refresh()->keep_alive)->toBeTrue();
    expect($process->restart_on_deploy)->toBeFalse();
    expect($process->source_definition_id)->toBe($definition->id);
    expect(app(ContainerProcesses::class)->units($instance->refresh())[0]['content'])->toContain('--port='.$instance->vite_port);

    $cloneOperation = containerCreate($project->refresh(), $node, 'capture-edit-child');
    $clone = Instance::query()->findOrFail($cloneOperation->target_id);
    $copy = $clone->processes()->sole();
    expect($copy->keep_alive)->toBeTrue();
    expect($copy->runtime_config['preset'])->toBe('vp-dev');
    expect($copy->source_definition_id)->toBe($definition->id);
})->with(['fresh cutover' => false, 'historical repair' => true]);
