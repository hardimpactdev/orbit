<?php

declare(strict_types=1);

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\AppDev\ViteProcessLifecycle;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\VpDevPreset;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

/** The real wake and Vite lifecycle, replacing only agent execution and elapsed time. */
function hibernatedViteCheckout(): array
{
    test()->freezeTime();
    Queue::fake();
    Http::preventStrayRequests();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'apps', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit',
    ]));
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Wake fixture', 'slug' => 'wake-fixture', 'type' => 'laravel-app',
        'repository_url' => 'https://github.com/hardimpactdev/wake-fixture', 'default_branch' => 'main', 'root' => 'public', 'php_version' => '8.5']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/home/orbit/apps/wake-fixture/default', 'branch' => 'main', 'root' => 'public', 'vite_port' => 5173,
        'status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => false]);
    Process::query()->create(['owner_type' => 'instance', 'owner_id' => $instance->id, 'name' => 'vite', 'runtime' => 'systemd',
        'runtime_config' => ['preset' => 'vp-dev', 'command' => VpDevPreset::command()], 'desired_state' => 'running', 'status' => 'active', 'keep_alive' => false]);
    DB::table('vite_port_assignments')->insert(['instance_id' => $instance->id, 'node_id' => $node->id, 'port' => 5173]);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'wake-fixture.test', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);
    $state = (object) ['started' => false, 'awake' => false, 'ready' => true, 'busy' => false, 'startRefused' => false, 'throwOn' => null];
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldReceive('run')->andReturnUsing(function (Node $node, string $command, array $input = []) use ($state): array {
        // A removed command fails exactly as it does in the deployed runner.
        app(AgentContract::class)->command($command);
        if ($state->throwOn === $command) {
            throw new LogicException('private runtime detail');
        }
        switch ($command) {
            case 'vite.select-port':
                return ['port' => $state->busy ? $input['preferred'] + 1 : $input['preferred']];
            case 'vite.check-listener':
                return ['owned' => $state->started, 'ready' => $state->started && $state->ready];
            case 'vite.set-traffic':
                return [];
            case 'hibernation.times':
                return ['times' => []];
            case 'hibernation.marker':
                expect($state->started)->toBeTrue();
                expect($input['path'])->toBe(RuntimeHibernation::awakePath('app-instance-'.test()->wakeInstanceId));
                $state->awake = true;

                return [];
            case 'process.control':
                if ($input['operation'] === 'start' && $state->startRefused) {
                    throw new AgentCommandRefused($command, 'process.start_failed', 'Strict-port startup failed.');
                }
                $state->started = $input['operation'] === 'start';

                return [];
            case 'process.status':
                return ['state' => $state->started ? 'active' : 'inactive'];
            default:
                throw new LogicException('Unexpected agent command: '.$command);
        }
    });
    app()->instance(AgentCommandRunner::class, $agents);
    $time = 0.0;
    app()->instance(ViteProcessLifecycle::class, new ViteProcessLifecycle(
        app(AppDevSourceOperationLock::class), app(VitePortAllocator::class), app(VitePortRuntime::class),
        clock: static function () use (&$time): float {
            return $time += 20.0;
        }, wait: static function (): void {},
    ));
    test()->wakeInstanceId = $instance->id;

    return [$instance, $state];
}

/** Calls only the test application's activation endpoint; never contacts a site. */
function viteWakePoll(Instance $instance, string $uri = '/'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])->withHeader('X-Forwarded-Uri', $uri)
        ->get('/api/v1/runtime-activations/app-instance/'.$instance->id);
}

function assertRecordedWakePort(Instance $instance): void
{
    expect($instance->refresh()->vite_port)->toBe(5173);
    test()->assertDatabaseHas('vite_port_assignments', ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'port' => 5173]);
    test()->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
}

describe('host-checkout Vite wake', function (): void {
    it('holds the visit, starts Vite on its recorded port and writes the awake marker without publication', function (): void {
        [$instance, $state] = hibernatedViteCheckout();
        // The automatic-deploy projection mark is independent of wake and must stay untouched.
        expect($instance->refresh()->development_projection_pending)->toBeTrue();

        viteWakePoll($instance, '/form?step=2')->assertStatus(307)->assertHeader('Location', '/form?step=2')->assertContent('');

        expect($state->started)->toBeTrue()->and($state->awake)->toBeTrue();
        expect($instance->refresh()->development_projection_pending)->toBeTrue();
        assertRecordedWakePort($instance);
    });

    it('sends the visitor only to a path on its own host', function (string $uri): void {
        [$instance] = hibernatedViteCheckout();

        viteWakePoll($instance, $uri)->assertStatus(307)->assertHeader('Location', '/');
    })->with(['https://evil.example/', '//evil.example/', '/\\evil.example/']);

    it('answers a plain 503 and keeps the recorded port when it is taken, startup is refused or readiness fails', function (string $failure): void {
        [$instance, $state] = hibernatedViteCheckout();
        $state->busy = $failure === 'busy';
        $state->startRefused = $failure === 'start';
        $state->ready = $failure !== 'readiness';

        viteWakePoll($instance)->assertServiceUnavailable()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('The recorded port 5173 is not available')->assertDontSee('<script', false);

        expect($state->started)->toBeFalse()->and($state->awake)->toBeFalse();
        assertRecordedWakePort($instance);

        $state->busy = false;
        $state->startRefused = false;
        $state->ready = true;
        viteWakePoll($instance)->assertStatus(307)->assertHeader('Location', '/');
        expect($state->started)->toBeTrue()->and($state->awake)->toBeTrue();
    })->with(['busy', 'start', 'readiness']);

    it('answers a plain 503 for an unexpected wake exception without exposing its details', function (string $command): void {
        [$instance, $state] = hibernatedViteCheckout();
        $state->throwOn = $command;

        viteWakePoll($instance)->assertServiceUnavailable()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('The runtime wake failed')->assertDontSee('private runtime detail');

        expect($state->awake)->toBeFalse();
        assertRecordedWakePort($instance);
    })->with(['vite.check-listener', 'vite.select-port', 'vite.set-traffic', 'process.control', 'process.status']);
});
