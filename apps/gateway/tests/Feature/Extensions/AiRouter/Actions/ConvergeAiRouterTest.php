<?php

declare(strict_types=1);

use App\Actions\Processes\CreateProcessAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\DatabaseConnection;
use App\Models\Node;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Actions\ConvergeAiRouterAction;
use Orbit\Extensions\AiRouter\Actions\DisableAiRouterAction;
use Orbit\Extensions\AiRouter\Actions\EnableAiRouterAction;
use Orbit\Extensions\AiRouter\Actions\ReconvergeAiRouterConnectionAction;
use Orbit\Extensions\AiRouter\Data\EnableAiRouterData;
use Orbit\Extensions\AiRouter\Domain\AiRouterCollectorEnvironment;
use Orbit\Extensions\AiRouter\Domain\AiRouterLifecycleLock;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\RecordingAiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Models\AiRouterProcessSource;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'collector', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.8', 'public_ssh_host' => '192.0.2.8']);
    $this->connection = DatabaseConnection::query()->create(['name' => 'Cache', 'slug' => 'cache', 'driver' => 'redis', 'host' => '192.0.2.50', 'port' => 6379]);
    app(ExtensionStore::class)->set('ai-router', true);
    app(AiRouterState::class)->enable($this->node->id, 'cache', 'http://127.0.0.1:8317', 'management', 'read', 'control');
    app()->instance(ProcessRuntimeManager::class, new ProcessesApiFakeRuntimeManager);
    $environment = app(AiRouterCollectorEnvironment::class)->build($this->connection);
    $this->process = app(CreateProcessAction::class)->bootstrapCollector(AiRouterProcess::data($this->node, $environment))['process'];
    AiRouterProcessSource::query()->create(['process_id' => $this->process->id, 'source_revision' => app(AgentDelivery::class)->pin($this->node), 'activation_pending' => false]);
});

it('retries failed or pending Process activation even when specification and source are current', function (array $attributes): void {
    $this->process->forceFill($attributes)->save();
    app(ConvergeAiRouterAction::class)->execute();
    expect(app(RecordingAiRouterRuntimeLifecycle::class)->converged)->toBeTrue();
})->with([['attributes' => ['systemd_activation_pending' => true]], ['attributes' => ['status' => 'failed']]]);

it('leaves a completed matching collector untouched', function (): void {
    app(ConvergeAiRouterAction::class)->execute();
    expect(app(RecordingAiRouterRuntimeLifecycle::class)->converged)->toBeFalse();
});

it('reconverges rotated connection credentials while the extension switch is off', function (): void {
    app(ExtensionStore::class)->set('ai-router', false);
    $this->connection->update(['password' => 'rotated']);
    app(ReconvergeAiRouterConnectionAction::class)->afterCommit($this->connection);
    expect(app(RecordingAiRouterRuntimeLifecycle::class)->environment['AI_ROUTER_CACHE_PASSWORD'])->toBe('rotated');
});

it('rechecks configured state after a waiting tick acquires the lifecycle lock', function (): void {
    $lock = Mockery::mock(Lock::class);
    Cache::shouldReceive('lock')->once()->with(AiRouterLifecycleLock::Name, 600)->andReturn($lock);
    $lock->shouldReceive('block')->once()->with(60, Mockery::type(Closure::class))->andReturnUsing(function (int $seconds, Closure $operation): bool {
        // A teardown completed while this tick waited to acquire the same lock.
        app(AiRouterState::class)->disable();

        return $operation();
    });
    app(ConvergeAiRouterAction::class)->execute();
    expect(app(RecordingAiRouterRuntimeLifecycle::class)->converged)->toBeFalse();
});

it('keeps pending rename work intact if setup or teardown is requested before the first tick', function (bool $setup): void {
    DB::table('ai_router_cutovers')->insert(['process_id' => $this->process->id]);
    $action = fn () => $setup
        ? app(EnableAiRouterAction::class)->execute(new EnableAiRouterData(999, 'different-cache', 'http://different-router:8317', 'replacement'))
        : app(DisableAiRouterAction::class)->execute();
    expect($action)->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('ai_router.cutover_pending'));
    expect(DB::table('ai_router_cutovers')->count())->toBe(1)
        ->and($this->process->fresh())->not->toBeNull()
        ->and(app(AiRouterState::class)->enabled())->toBeTrue()
        ->and(app(AiRouterState::class)->nodeId())->toBe($this->node->id);
})->with([false, true]);
