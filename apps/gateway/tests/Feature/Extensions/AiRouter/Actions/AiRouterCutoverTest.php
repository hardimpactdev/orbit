<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Actions\ConvergeAiRouterAction;
use Orbit\Extensions\AiRouter\Actions\ShowAiRouterStatusAction;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterFootprintArtifact;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterSourcePublisher;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Infrastructure\RecordingAiRouterPublicationManager;
use Tests\Support\ProcessesApiFakeRuntimeManager;
use Tests\Support\RecordedAiRouterState;

it('defers a rollout visit during recorded cutover state and retries publication without losing model access', function (bool $failFirst, bool $switchOff): void {
    $node = Node::query()->forceCreate(['id' => 17, 'name' => 'services', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.17']);
    $connection = DatabaseConnection::query()->create(['name' => 'Valkey', 'slug' => 'cache', 'driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379]);
    $process = RecordedAiRouterState::seed($node, $connection);
    $management = Route::query()->forceCreate(['id' => 104, 'kind' => 'custom_proxy', 'node_id' => 17, 'domain' => 'cli-proxy-api.orbit', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $management->customProxy()->create(['node_id' => 17, 'upstream' => 'http://127.0.0.1:8317']);
    $management->update(['status' => 'active']);
    $vm = DB::table('task_vms')->insertGetId(['provider' => 'incus', 'name' => 'held', 'state' => 'ready', 'wireguard_ip' => '10.44.0.130', 'model_key' => 'held-key-bytes', 'model_proxy_origin' => 'http://10.44.0.3:8317']);
    $encrypted = DB::table('settings')->where('is_secret', true)->pluck('value', 'id')->all();
    $cache = app(AiRouterCache::class);
    $cache->put('orbit:proxycli:snapshot', 'old snapshot');
    $cache->put('orbit:proxycli:lock', 'old holder');
    $cache->put('unrelated', 'keep');
    $cache->put('orbit:ai-router:snapshot', 'new snapshot');
    $runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $runtime);
    app()->bind(AiRouterRuntimeLifecycle::class, NativeAiRouterRuntimeLifecycle::class);
    $ssh = Mockery::mock(SshExecutor::class);
    $commands = [];
    $published = false;
    $ssh->shouldReceive('execute')->andReturnUsing(function (SshConnection $connection, RemoteCommand $command) use (&$commands, &$published): CommandResult {
        $commands[] = $command;

        $output = '';
        if (in_array('sha256sum', $command->arguments, true)) {
            $output = hash_file('sha256', base_path('extensions/ai-router/resources/collector.py')).' collector.py';
        } elseif ($command->protectedInput !== null) {
            $output = $published ? AiRouterSourcePublisher::Unchanged : AiRouterSourcePublisher::Published;
            $published = true;
        }

        return new CommandResult(0, $output, '', 0, false);
    });
    app()->instance(SshExecutor::class, $ssh);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/tmp/test-key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/tmp/test-known-hosts');
    app()->instance(KnownHostsStore::class, $hosts);
    (require base_path('extensions/ai-router/database/migrations/2026_11_16_000001_rename_ai_router_state.php'))->up();
    if ($switchOff) {
        app(ExtensionStore::class)->set('ai-router', false);
    }
    // The real rollout footprint visits before the first tick, with only the retired unit on the Node.
    $footprint = new NodeFootprint([app(AiRouterFootprintArtifact::class)]);
    $footprint->converge($node);
    expect($runtime->restarted)->toBeEmpty()->and($commands)->toBeEmpty()
        ->and(app(ShowAiRouterStatusAction::class)->execute()->cutoverPending)->toBeTrue();
    if ($failFirst) {
        $publication = Mockery::mock(AiRouterPublicationManager::class);
        $publication->shouldReceive('converge')->once()->andThrow(new RuntimeException('Publication unavailable'));
        app()->instance(AiRouterPublicationManager::class, $publication);
        expect(fn () => app(ConvergeAiRouterAction::class)->execute())->toThrow(RuntimeException::class, 'Publication unavailable');
        expect($process->refresh()->status->value)->toBe('provisioning')
            ->and(app(ShowAiRouterStatusAction::class)->execute()->cutoverPending)->toBeTrue();
        $footprint->converge($node, force: true);
        expect($runtime->restarted)->toBeEmpty();
        app()->instance(AiRouterPublicationManager::class, app(RecordingAiRouterPublicationManager::class));
    }
    $this->artisan('orbit:ai-router-converge')->assertSuccessful();
    expect($commands[0]->arguments)->toBe(['sudo', '-n', '-u', 'root', '-H', 'bash', '-seu', '--', '163']);
    expect($commands[0]->input)->toContain('cli-proxy-api-collector.service', 'X-Orbit-Process-ID=$id')
        ->not->toContain('orbit-process-163-ai-router-collector.service');
    expect($process->refresh()->runtime_config['command'])->toBe(['/usr/bin/python3', '/var/lib/orbit/ai-router/collector.py'])
        ->and($process->runtime_config['environment'])->toHaveKeys(['AI_ROUTER_READ_TOKEN', 'AI_ROUTER_MANAGEMENT_KEY'])
        ->and($cache->get('orbit:proxycli:snapshot'))->toBeNull()->and($cache->get('orbit:proxycli:lock'))->toBeNull()
        ->and($cache->get('unrelated'))->toBe('keep')->and($cache->get('orbit:ai-router:snapshot'))->toBe('new snapshot')
        ->and(Route::query()->pluck('domain')->all())->toBe(['cli-proxy-api.orbit'])
        ->and(DB::table('settings')->where('is_secret', true)->pluck('value', 'id')->all())->toBe($encrypted)
        ->and(DB::table('settings')->where('key', 'caddy.certificate.proxycli')->exists())->toBeFalse()
        ->and(DB::table('settings')->where('key', 'like', 'ai_router.anchor.%')->count())->toBe(1)
        ->and(DB::table('task_vms')->where('id', $vm)->value('model_key'))->toBe('held-key-bytes')
        ->and(DB::table('task_vms')->where('id', $vm)->value('ai_router_origin'))->toBe('http://10.44.0.3:8317')
        ->and(app(ShowAiRouterStatusAction::class)->execute()->cutoverPending)->toBeFalse();
    $before = count($runtime->restarted);
    $footprint->converge($node, force: true);
    $this->artisan('orbit:ai-router-converge')->assertSuccessful();
    expect(count($runtime->restarted))->toBe($before);
})->with([[false, false], [true, false], [false, true]]);
