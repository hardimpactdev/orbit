<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Actions\ConvergeAiRouterAction;
use Orbit\Extensions\AiRouter\Actions\ShowAiRouterStatusAction;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterFootprintArtifact;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Infrastructure\RecordingAiRouterPublicationManager;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\RecordedAiRouterState;

it('defers a rollout visit during recorded cutover state and retries publication without losing model access', function (bool $failFirst, bool $switchOff, bool $failCleanup): void {
    $node = Node::query()->forceCreate(['id' => 17, 'name' => 'services', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.17']);
    $connection = DatabaseConnection::query()->create(['name' => 'Valkey', 'slug' => 'cache', 'driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379]);
    $process = RecordedAiRouterState::seed($node, $connection);
    $management = Route::query()->forceCreate(['id' => 104, 'kind' => 'custom_proxy', 'node_id' => 17, 'domain' => 'cli-proxy-api.orbit', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $management->customProxy()->create(['node_id' => 17, 'upstream' => 'http://127.0.0.1:8317']);
    $management->update(['status' => 'active']);
    $vm = DB::table('task_vms')->insertGetId(['provider' => 'incus', 'name' => 'held', 'state' => 'ready', 'wireguard_ip' => '10.44.0.130', 'model_key' => 'held-key-bytes', 'ai_router_origin' => 'http://10.44.0.3:8317']);
    $encrypted = DB::table('settings')->where('is_secret', true)->pluck('value', 'id')->all();
    $cache = app(AiRouterCache::class);
    $cache->put('orbit:proxycli:snapshot', 'old snapshot');
    $cache->put('orbit:proxycli:lock', 'old holder');
    $cache->put('unrelated', 'keep');
    $cache->put('orbit:proxycli:backoff:limited', 'deadline', 300);
    $cache->put('orbit:proxycli:account:limited', 'scheduled', 600);
    $expectedConverges = $failCleanup ? 3 : 2;
    $converged = [];
    $activated = [];
    $removed = [];
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldReceive('assertCanStart')->andReturnNull();
    $runtime->shouldReceive('remove')->andReturnUsing(function (Process $process) use (&$removed): void {
        expect($process->name)->toBe('cli-proxy-api-collector');
        $removed[] = $process->id;
    });
    $runtime->shouldReceive('status')->andReturn('running');
    $runtime->shouldReceive('converge')->andReturnUsing(function (Process $process) use (&$converged, &$activated): void {
        $converged[] = $process->id;
        if ($process->systemd_activation_pending || ! in_array($process->id, $activated, true)) {
            $activated[] = $process->id;
        }
        $process->systemd_activation_pending = false;
        $process->save();
    });
    app()->instance(ProcessRuntimeManager::class, $runtime);
    app()->bind(AiRouterRuntimeLifecycle::class, NativeAiRouterRuntimeLifecycle::class);
    $agents = new FakeAgentCommandRunner()->answer('caddy.remove-certificate', [])
        ->answer('ai-router.remove-source', static function (array $input) use (&$failCleanup): array {
            if ($failCleanup && $input['retired']) {
                $failCleanup = false;
                throw new RuntimeException('Could not remove the retired collector files');
            }

            return ['removed' => true];
        });
    app()->instance(AgentCommandRunner::class, $agents);
    if ($switchOff) {
        app(ExtensionStore::class)->set('ai-router', false);
    }
    // The real rollout footprint visits before the first tick, with only the retired unit on the Node.
    $footprint = new NodeFootprint(app(AgentDelivery::class), [app(AiRouterFootprintArtifact::class)]);
    $footprint->converge($node);
    expect($converged)->toBeEmpty()->and($agents->calls)->toBeEmpty()
        ->and(app(ShowAiRouterStatusAction::class)->execute()->cutoverPending)->toBeTrue();
    if ($failFirst) {
        $publication = Mockery::mock(AiRouterPublicationManager::class);
        $publication->shouldReceive('converge')->once()->andThrow(new RuntimeException('Publication unavailable'));
        app()->instance(AiRouterPublicationManager::class, $publication);
        expect(fn () => app(ConvergeAiRouterAction::class)->execute())->toThrow(RuntimeException::class, 'Publication unavailable');
        expect(Process::query()->find(163))->toBeNull()
            ->and(app(ShowAiRouterStatusAction::class)->execute()->cutoverPending)->toBeTrue();
        $footprint->converge($node, force: true);
        expect($converged)->toBeEmpty();
        app()->instance(AiRouterPublicationManager::class, app(RecordingAiRouterPublicationManager::class));
    }
    if ($failCleanup) {
        expect(fn () => app(ConvergeAiRouterAction::class)->execute())->toThrow(RuntimeException::class, 'Could not remove the retired collector files');
        expect(app(ShowAiRouterStatusAction::class)->execute()->cutoverPending)->toBeTrue();
    }
    $this->artisan('orbit:ai-router-converge')->assertSuccessful();
    expect(Process::query()->find(163))->toBeNull()->and($removed)->toBe([163]);
    $process = Process::query()->where('name', 'ai-router-collector')->sole();
    expect($process->runtime_config['command'])->toBe(['/usr/local/bin/orbit-agent', 'service', 'ai-router-collector'])
        ->and($process->runtime_config['environment'])->toHaveKeys(['AI_ROUTER_READ_TOKEN', 'AI_ROUTER_MANAGEMENT_KEY'])
        ->and($cache->get('orbit:proxycli:snapshot'))->toBeNull()->and($cache->get('orbit:proxycli:lock'))->toBeNull()
        ->and($cache->get('orbit:ai-router:lock'))->toBeNull()
        ->and($cache->get('orbit:ai-router:backoff:limited'))->toBe('deadline')
        ->and($cache->get('orbit:ai-router:account:limited'))->toBe('scheduled')
        ->and($cache->get('unrelated'))->toBe('keep')->and($cache->get('orbit:ai-router:snapshot'))->toBe('old snapshot')
        ->and(Route::query()->pluck('domain')->all())->toBe(['cli-proxy-api.orbit'])
        ->and(DB::table('settings')->where('is_secret', true)->pluck('value', 'id')->all())->toBe($encrypted)
        ->and(DB::table('settings')->where('key', 'caddy.certificate.proxycli')->exists())->toBeFalse()
        ->and(DB::table('settings')->where('key', 'like', 'ai_router.anchor.%')->count())->toBe(1)
        ->and(DB::table('task_vms')->where('id', $vm)->value('model_key'))->toBe('held-key-bytes')
        ->and(DB::table('task_vms')->where('id', $vm)->value('ai_router_origin'))->toBe('http://10.44.0.3:8317')
        ->and(app(ShowAiRouterStatusAction::class)->execute()->cutoverPending)->toBeFalse();
    expect(array_unique($converged))->toBe([$process->id])->and($activated)->toBe([$process->id]);
    expect($agents->calls)->toContain(
        ['node' => $node->name, 'command' => 'ai-router.remove-source', 'input' => ['retired' => true]],
        ['node' => $node->name, 'command' => 'caddy.remove-certificate', 'input' => ['directory' => '/etc/caddy/orbit-proxycli-cert-current']],
    );
    $footprint->converge($node, force: true);
    $this->artisan('orbit:ai-router-converge')->assertSuccessful();
    expect($activated)->toBe([$process->id]);
    expect($converged)->toHaveCount($expectedConverges);
})->with([[false, false, false], [true, false, false], [false, true, false], [false, false, true]]);
