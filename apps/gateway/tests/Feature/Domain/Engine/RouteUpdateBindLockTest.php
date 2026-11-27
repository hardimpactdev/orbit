<?php

declare(strict_types=1);

use App\Actions\Routes\RouteMutationState;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Engine\Engine;
use App\Domain\Engine\MachineBus;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\RouteWriteNodes;
use App\Domain\Routes\NativeRouteUpdateInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** A bus whose Nodes report every action and feature, and that keeps the orders it publishes. */
final class BindLockBus implements MachineBus
{
    /** @var list<int> */
    public array $orders = [];

    public function publishOrder(int $node, SignedExecutorOrder $order, ?Closure $claim = null): void
    {
        if ($claim === null || $claim()) {
            $this->orders[] = $node;
        }
    }

    public function publishStart(int $operation): void {}

    public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void {}

    public function nudge(int $operation, string $id): void {}

    public function putContent(int $node, string $content): array
    {
        return ['sha256' => hash('sha256', $content)];
    }

    public function nodeActions(int $node): array
    {
        return ['tls.ensure_csr', 'route.publish_site', 'route.withdraw_site', 'dns.publish_records'];
    }

    public function nodeFeatures(int $node): array
    {
        return [];
    }

    public function nodeTargetActions(int $node): array
    {
        return [];
    }

    public function nodeClock(int $node): ?bool
    {
        return null;
    }
}

beforeEach(function (): void {
    Queue::fake();
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-root-certificate');
    config(['orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), 'orbit.engine.kinds' => ['route.update']]);
    $this->bus = new BindLockBus;
    $this->app->instance(MachineBus::class, $this->bus);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'update-node', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key', 'ssh_host_fingerprint' => 'fixture']));
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $control = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-control-key']));
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $this->site = function (string $slug, string $domain): Route {
        $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'node-package',
            'repository_url' => "https://github.com/example/{$slug}.git", 'default_branch' => 'main', 'root' => 'public']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => $slug,
            'checkout_path' => "/home/orbit/apps/{$slug}", 'status' => 'active', 'source_layout' => 'worktree', 'root' => 'public',
            'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
        $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $this->node->id,
            'provenance' => 'explicit', 'publication' => 'private', 'domain' => $domain, 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active', 'sites_published' => true]);

        return $route->refresh();
    };
    // An admitted, queued update of shop.fixture.test to store.fixture.test, as AdmitRouteOperationAction records it.
    $this->admit = function (): Operation {
        $route = ($this->site)('shop', 'shop.fixture.test');
        $candidate = app(RouteMutationState::class)->reserve($route, 'store.fixture.test');
        $input = ['mode' => 'replace_domain', 'domain' => 'store.fixture.test', 'publication' => 'private', 'web_root' => null,
            'candidate_route_id' => $candidate->id, 'allow_generated' => false];
        $capture = app(NativeRouteUpdateInput::class)->capture($route->refresh()->load('targets.instance.node'), $input);

        return Operation::query()->forceCreate(['kind' => 'route.update', 'status' => OperationStatus::Queued, 'target_type' => 'route',
            'target_id' => $route->id, 'node_id' => null, 'input' => [...$input, 'route_update' => $capture['intent'], 'execution_secrets' => []], 'state' => []]);
    };
    // Each outermost transaction's hold in ms, and whether it read the catalog (route_targets) while it held the lock.
    $this->holds = [];
    $outside = DB::transactionLevel();
    $began = null;
    $planned = false;
    Event::listen(TransactionBeginning::class, function () use (&$began, &$planned, $outside): void {
        if (DB::transactionLevel() === $outside + 1) {
            [$began, $planned] = [hrtime(true), false];
        }
    });
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$planned, $outside): void {
        if (DB::transactionLevel() > $outside && str_contains($query->sql, 'route_targets')) {
            $planned = true;
        }
    });
    Event::listen(TransactionCommitted::class, function () use (&$began, &$planned, $outside): void {
        if (DB::transactionLevel() === $outside && $began !== null) {
            $this->holds[] = ['ms' => (hrtime(true) - $began) / 1e6, 'planned' => $planned];
            $began = null;
        }
    });
});

it('binds a large Node\'s update without planning in the write transaction, and plans in its first step with no transaction held', function (): void {
    foreach (range(1, 125) as $index) {
        ($this->site)("sibling{$index}", "sibling{$index}.fixture.test");
    }
    $operation = ($this->admit)();
    $lines = substr_count(app(NodeCaddyfileRenderer::class)->render($this->node)->content, "\n");

    $this->holds = [];
    app(StartSwampRun::class)->start($operation);
    $bind = $this->holds;

    $this->holds = [];
    $started = hrtime(true);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    $check = (hrtime(true) - $started) / 1e6;

    $current = OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', 'current')->sole();
    fwrite(STDERR, sprintf("\nbind lock: %d-line Caddyfile, longest bind hold %.1f ms, current step %.1f ms, longest hold during it %.1f ms\n",
        $lines, max(array_column($bind, 'ms')), $check, max(array_column($this->holds, 'ms'))));
    expect($lines)->toBeGreaterThan(4000)
        ->and($operation->refresh()->lock_key)->not->toBeNull()
        ->and(array_filter($bind, static fn (array $hold): bool => $hold['planned']))->toBe([])
        ->and(max(array_column($bind, 'ms')))->toBeLessThan(1000)
        ->and($current->status)->toBe('done')
        ->and(array_filter($this->holds, static fn (array $hold): bool => $hold['planned']))->toBe([])
        ->and(max(array_column($this->holds, 'ms')))->toBeLessThan(1000)
        ->and($this->bus->orders)->not->toBe([]);
});

it('refuses a sibling Route published between admission and bind at the first step, before any Node order', function (): void {
    $operation = ($this->admit)();
    ($this->site)('blog', 'blog.fixture.test');

    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);

    $operation->refresh();
    expect(OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', 'current')->sole()->error_code)->toBe('route.publication_stale')
        ->and($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('route.publication_stale')
        ->and($operation->lock_key)->toBeNull()
        ->and($this->bus->orders)->toBe([]);
});

it('keeps the Node hold exclusive from the bind on: a Route write admitted while the check runs is refused', function (): void {
    $operation = ($this->admit)();
    app(StartSwampRun::class)->start($operation);
    $writer = Operation::query()->forceCreate(['kind' => 'route.create', 'status' => OperationStatus::Queued, 'target_type' => 'route', 'target_id' => 999,
        'node_id' => null, 'input' => ['site_nodes' => [$this->node->id]], 'state' => []]);

    expect(fn () => RouteWriteNodes::refuseBesideEngine($writer))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.publication_held'));
});
