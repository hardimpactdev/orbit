<?php

declare(strict_types=1);

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteVitePortRuntime;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;

function vite_port_runtime(AgentCommandRunner $agents): RemoteVitePortRuntime
{
    return new RemoteVitePortRuntime($agents, app(RemoteAppDevCaddyManager::class), app(DevelopmentProjectionOperationLock::class), app(SystemdProcessRenderer::class));
}

function vite_port_node(): Node
{
    return new Node(['name' => 'vite', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '192.0.2.1']);
}

it('sends the preferred port and exclusions to the agent and returns its selection', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('vite.select-port', ['port' => 5212]);

    expect(vite_port_runtime($agents)->selectPort(vite_port_node(), 5210, [5211]))->toBe(5212)
        ->and($agents->calls[0]['input'])->toBe(['preferred' => 5210, 'excluded' => [5211]]);
});

it('reports finite exhaustion when the final candidate is excluded', function (): void {
    $runtime = vite_port_runtime(new FakeAgentCommandRunner()->refuse('vite.select-port', 'vite.ports_exhausted'));

    expect(fn () => $runtime->selectPort(vite_port_node(), 65535, [65535]))->toThrow(ProcessOperationException::class, 'No available Vite port remains');
});

it('maps a failed port check and rejects malformed agent results', function (): void {
    $agents = new FakeAgentCommandRunner()->refuse('vite.select-port', 'vite.node_failed');
    expect(fn () => vite_port_runtime($agents)->selectPort(vite_port_node(), 5210, []))
        ->toThrow(fn (ProcessOperationException $error) => expect($error->errorCode)->toBe('vite.port_check_failed'));
    $agents->answer('vite.select-port', ['port' => 0]);
    expect(fn () => vite_port_runtime($agents)->selectPort(vite_port_node(), 5210, []))
        ->toThrow(fn (ProcessOperationException $error) => expect($error->errorCode)->toBe('vite.port_check_invalid'));
});

it('writes the Vite environment from the application directory', function (string $root, bool $laravel, string $suffix): void {
    $node = Node::query()->create(['name' => 'vite-prepare', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '192.0.2.1']);
    $node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create(['name' => 'Vite prepare', 'slug' => 'vite-prepare', 'repository_url' => 'git@example.test:vite.git', 'root' => $root]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main',
        'checkout_path' => '/srv/apps/vite/main', 'source_is_laravel' => $laravel, 'vite_port' => 5210,
    ]);
    $agents = new FakeAgentCommandRunner()->answer('vite.set-traffic', []);

    vite_port_runtime($agents)->prepare(new Process, $instance);

    expect($agents->calls[0]['input'])->toBe([
        'operation' => 'environment',
        'application' => '/srv/apps/vite/main'.$suffix,
        'path' => SystemdProcessRenderer::viteEnvironmentPath($instance->id),
        'instance_id' => $instance->id,
        'marker' => "/dev/shm/orbit/hibernation/app-instance-{$instance->id}.awake",
        'content' => "# Orbit Instance {$instance->id}\nORBIT_DEV_SERVER_PORT=5210\n",
    ]);
})->with([
    'nested Laravel dependencies only in the app' => ['server/web/public', true, '/server/web'],
    'root public Laravel app' => ['public', true, ''],
    'non Laravel retains checkout dependencies' => ['server/web/public', false, ''],
]);

it('is ready only when the agent finds the owned listener serving the Vite client', function (bool $owned, bool $ready): void {
    $agents = new FakeAgentCommandRunner()->answer('vite.check-listener', ['owned' => $owned, 'ready' => $ready]);
    $instance = new Instance;
    $instance->setRelation('node', vite_port_node());
    $process = new Process;
    $process->id = 7;
    $process->name = 'vite';

    expect(vite_port_runtime($agents)->ready($process, $instance, 5210))->toBe($ready)
        ->and($agents->calls[0]['input'])->toMatchArray(['port' => 5210, 'process_id' => 7, 'probe' => true]);
})->with([[true, true], [true, false], [false, false]]);
