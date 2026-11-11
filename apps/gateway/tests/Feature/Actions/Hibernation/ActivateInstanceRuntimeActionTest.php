<?php

declare(strict_types=1);

use App\Domain\Hibernation\HibernationWakeFailureStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Routes\NativeCaddyPublicationProjection;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** The real wake/lifecycle path, with only agent execution and queued native dispatch replaced. */
function hibernatedViteCheckout(): array
{
    test()->freezeTime();
    Queue::fake([StartAdmittedSwampRun::class]);
    Http::preventStrayRequests();
    SwampNodeFixture::ensure();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'apps', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Wake fixture', 'slug' => 'wake-fixture', 'type' => 'laravel-app',
        'repository_url' => 'https://github.com/hardimpactdev/wake-fixture', 'default_branch' => 'main', 'root' => 'public', 'php_version' => '8.5']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/home/orbit/apps/wake-fixture/default', 'branch' => 'main', 'root' => 'public', 'vite_port' => 5173,
        'status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => false]);
    Process::query()->create(['owner_type' => 'instance', 'owner_id' => $instance->id, 'name' => 'vite', 'runtime' => 'systemd',
        'runtime_config' => ['preset' => 'vp-dev'], 'desired_state' => 'running', 'status' => 'active', 'keep_alive' => false]);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'wake-fixture.test', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);
    $state = (object) ['started' => false, 'awake' => false, 'events' => [], 'exception' => null];
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldReceive('run')->andReturnUsing(function (Node $node, string $command, array $input = []) use ($state): array {
        // A removed command must fail here exactly as it does in the deployed runner.
        app(AgentContract::class)->command($command);
        if ($state->exception !== null) {
            throw $state->exception;
        }
        switch ($command) {
            case 'vite.select-port':
                return ['port' => $input['preferred']];
            case 'vite.check-listener':
                return ['owned' => $state->started, 'ready' => $state->started];
            case 'vite.set-traffic':
                $state->events[] = $input['operation'];

                return [];
            case 'hibernation.times':
                return ['times' => []];
            case 'hibernation.marker':
                expect($state->started)->toBeTrue();
                expect($input['path'])->toBe(RuntimeHibernation::awakePath('app-instance-'.test()->wakeInstanceId));
                $state->awake = true;
                $state->events[] = 'awake';

                return [];
            case 'process.control':
                expect($input['operation'])->toBe('start');
                expect(Operation::query()->sole()->status->value)->toBe('succeeded');
                $state->started = true;
                $state->events[] = 'start';

                return [];
            case 'process.status':
                return ['state' => $state->started ? 'active' : 'inactive'];
            default:
                throw new LogicException('Unexpected agent command: '.$command);
        }
    });
    app()->instance(AgentCommandRunner::class, $agents);
    test()->wakeInstanceId = $instance->id;

    return [$instance, $state];
}

/** Calls only the test application's activation endpoint; never contacts a site. */
function viteWakePoll(Instance $instance, string $uri = '/'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])->withHeader('X-Forwarded-Uri', $uri)
        ->get('/api/v1/runtime-activations/app-instance/'.$instance->id);
}

/** Projects the same native publication result the shared terminal reader supplies. */
function finishWakePublication(string $status): Operation
{
    $operation = Operation::query()->sole();
    $runId = 'c2a77e95-f547-4429-8661-5c4c65b2040b';
    $operation->forceFill(['status' => 'running', 'started_at' => now(), 'state' => ['swamp_run_id' => $runId]])->save();

    return app(NativeCaddyPublicationProjection::class)->apply($operation, [
        'run_id' => $runId, 'status' => $status, 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
        'error_code' => $status === 'succeeded' ? null : 'operation.native_failed',
        'result' => ['caddy' => ['version' => str_repeat('a', 32)], 'steps' => [NativeCaddyPublicationProjection::EndCheck => ['status' => $status]]],
    ]);
}

describe('host-checkout Vite wake', function (): void {
    it('starts Vite and writes the awake marker only after native publication succeeds', function (): void {
        [$instance, $state] = hibernatedViteCheckout();

        viteWakePoll($instance)->assertUnauthorized()->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');

        expect(app(HibernationWakeFailureStore::class)->get($instance->id))->toBeNull();
        expect($state->started)->toBeFalse()->and($state->awake)->toBeFalse();
        $operation = Operation::query()->sole();
        expect($operation->kind)->toBe('route.caddy_converge');
        $input = app(DirectOperation::class)->workflowInputs($operation);
        expect($input['intent']['caddy_publication']['caddy']['content'])->toContain('reverse_proxy 127.0.0.1:5173');
        finishWakePublication('succeeded');

        viteWakePoll($instance)->assertUnauthorized();

        expect($state->awake)->toBeTrue()->and(array_slice($state->events, -2))->toBe(['start', 'awake']);
        expect(app(HibernationWakeFailureStore::class)->get($instance->id))->toBeNull();
        Queue::assertPushed(StartAdmittedSwampRun::class, 1);
        test()->assertDatabaseCount('operations', 1);
    });

    it('reuses the running publication on every page poll without starting Vite', function (): void {
        [$instance, $state] = hibernatedViteCheckout();
        viteWakePoll($instance)->assertUnauthorized();
        Operation::query()->sole()->update(['status' => 'running', 'started_at' => now()]);

        viteWakePoll($instance)->assertUnauthorized();
        viteWakePoll($instance)->assertUnauthorized();

        expect($state->started)->toBeFalse()->and($state->awake)->toBeFalse();
        expect(app(HibernationWakeFailureStore::class)->get($instance->id))->toBeNull();
        Queue::assertPushed(StartAdmittedSwampRun::class, 1);
        test()->assertDatabaseCount('operations', 1);
    });

    it('keeps a terminal publication failure on the 503 failure page until explicit retry', function (string $status): void {
        [$instance, $state] = hibernatedViteCheckout();
        viteWakePoll($instance)->assertUnauthorized();
        finishWakePublication($status);
        viteWakePoll($instance)->assertUnauthorized();

        viteWakePoll($instance)->assertServiceUnavailable()->assertHeader('X-Orbit-Runtime-Activation-State', 'failed')
            ->assertSee('Caddy publication Operation');
        test()->travel(121)->seconds();
        viteWakePoll($instance)->assertServiceUnavailable();

        expect($state->started)->toBeFalse()->and($state->awake)->toBeFalse();
        Queue::assertPushed(StartAdmittedSwampRun::class, 1);
        test()->assertDatabaseCount('operations', 1);
    })->with(['failed', 'cancelled', 'interrupted']);

    it('shows a failure after the publication deadline instead of admitting another publication', function (): void {
        [$instance, $state] = hibernatedViteCheckout();
        viteWakePoll($instance)->assertUnauthorized();
        $operation = Operation::query()->sole();
        test()->travelTo(OperationRunBudgets::deadline($operation)->addSecond());
        viteWakePoll($instance)->assertUnauthorized();

        viteWakePoll($instance)->assertServiceUnavailable()->assertHeader('X-Orbit-Runtime-Activation-State', 'failed');

        expect($state->awake)->toBeFalse();
        Queue::assertPushed(StartAdmittedSwampRun::class, 1);
        test()->assertDatabaseCount('operations', 1);
    });

    it('shows unexpected wake exceptions as a persistent failure without exposing their details', function (): void {
        [$instance, $state] = hibernatedViteCheckout();
        // Marker reads intentionally tolerate errors; the Vite call must reach the same exception boundary.
        $state->exception = new LogicException('private runtime detail');
        viteWakePoll($instance)->assertUnauthorized();

        viteWakePoll($instance)->assertServiceUnavailable()->assertHeader('X-Orbit-Runtime-Activation-State', 'failed')
            ->assertSee('The runtime wake failed')->assertDontSee('private runtime detail');
        viteWakePoll($instance)->assertServiceUnavailable();

        expect($state->awake)->toBeFalse();
        Queue::assertNothingPushed();
    });

    it('starts one new publication on explicit retry and removes the retry flag from page polls', function (): void {
        [$instance, $state] = hibernatedViteCheckout();
        viteWakePoll($instance)->assertUnauthorized();
        finishWakePublication('failed');
        viteWakePoll($instance)->assertUnauthorized();
        viteWakePoll($instance)->assertServiceUnavailable();

        $page = viteWakePoll($instance, '/work?tab=one&orbit-wake-retry=1');
        $page->assertUnauthorized()->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');
        expect($page->getContent())->toContain('/work?tab=one');
        preg_match('/const uri = (.+);/', $page->getContent(), $match);
        expect(json_decode($match[1], true, flags: JSON_THROW_ON_ERROR))->toBe('/work?tab=one');
        viteWakePoll($instance, '/work?tab=one')->assertUnauthorized();

        expect($state->awake)->toBeFalse();
        Queue::assertPushed(StartAdmittedSwampRun::class, 2);
        test()->assertDatabaseCount('operations', 2);
    });
});
