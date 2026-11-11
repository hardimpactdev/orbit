<?php

declare(strict_types=1);

use App\Actions\Processes\DestroyProcessAction;
use App\Domain\Instances\InstanceState;
use App\Domain\Processes\ProcessRemovalGuard;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceNodeServiceTarget;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process as ShellProcess;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

it('refuses Process destroy before admission or runtime mutation and names every dependent Instance once', function (string $path): void {
    Http::preventStrayRequests();
    ShellProcess::preventStrayProcesses();
    Queue::fake();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active',
        'platform' => 'linux', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit']));
    $node->accessibleNodes()->attach($node);
    $project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'type' => ProjectType::NodePackage,
        'repository_url' => 'https://github.com/nckrtl/app.git', 'default_branch' => 'main']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'mailpit',
        'runtime' => 'docker', 'runtime_config' => ['image' => 'axllent/mailpit', 'ports' => ['0.0.0.0:1025:1025/tcp']],
        'desired_state' => 'running', 'status' => 'active']);
    $instances = [];
    foreach (['default', 'feature'] as $name) {
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name,
            'checkout_path' => '/home/orbit/apps/app/'.$name, 'status' => InstanceState::Active]);
        $instances[] = $instance;
        foreach (['MAIL', 'SECOND_MAIL'] as $prefix) {
            $instance->nodeServiceTargets()->create(['node_id' => $node->id, 'process_id' => $process->id,
                'protocol' => 'tcp', 'port' => 1025, 'host_env_key' => $prefix.'_HOST', 'port_env_key' => $prefix.'_PORT']);
        }
    }
    $processBefore = $process->refresh()->getRawOriginal();
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldNotReceive('remove');
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $message = "Process [mailpit] is attached to Instance [app/default] (id {$instances[0]->id}), Instance [app/feature] (id {$instances[1]->id}). Detach those Node service targets before destroying the Process.";

    if ($path === 'http') {
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
            ->deleteJson('/api/v1/processes/'.$process->id)->assertConflict()
            ->assertJsonPath('error.code', 'process.required_by_instance_node_service')
            ->assertJsonPath('error.message', $message);
    } else {
        try {
            app(DestroyProcessAction::class)->execute($process);
            test()->fail('An attached Process must not be destroyed.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('process.required_by_instance_node_service');
            expect($exception->getMessage())->toBe($message);
        }
    }

    expect($process->refresh()->getRawOriginal())->toEqual($processBefore);
    $this->assertDatabaseCount('instance_node_service_targets', 4);
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
})->with(['http', 'synchronous']);

it('does not block an unrelated Process or a Process whose final attachment was removed', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active',
        'platform' => 'linux', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit']));
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'mailpit',
        'runtime' => 'docker', 'runtime_config' => ['ports' => ['0.0.0.0:1025:1025/tcp']], 'desired_state' => 'running', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'type' => ProjectType::NodePackage,
        'repository_url' => 'https://github.com/nckrtl/app.git', 'default_branch' => 'main']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/home/orbit/apps/app/default', 'status' => InstanceState::Active]);
    $target = $instance->nodeServiceTargets()->create(['node_id' => $node->id, 'process_id' => $process->id,
        'protocol' => 'tcp', 'port' => 1025, 'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT']);
    $other = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'other',
        'runtime' => 'docker', 'runtime_config' => [], 'desired_state' => 'running', 'status' => 'active']);

    foreach (app()->tagged(ProcessRemovalGuard::class) as $guard) {
        $guard->assertRemovable($other);
    }
    $target->delete();
    foreach (app()->tagged(ProcessRemovalGuard::class) as $guard) {
        $guard->assertRemovable($process);
    }

    expect(InstanceNodeServiceTarget::query()->count())->toBe(0);
    expect(Operation::query()->count())->toBe(0);
});
