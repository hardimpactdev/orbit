<?php

declare(strict_types=1);

use App\Actions\Nodes\ListNodesAction;
use App\Domain\AgentView\AgentStateView;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\NodeUpdates;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Http\Controllers\Api\NodesController;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    $this->app->instance(WebSocketClient::class, Mockery::mock(WebSocketClient::class));
    $this->view = new CacheAgentStateView(new Repository(new ArrayStore));
    $this->app->instance(AgentStateView::class, $this->view);
    $this->view->putSubscriber(true, true, 3);
    $this->caller = node_list_fixture('operator', '10.44.0.9');
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
});

describe('node:list recorded projection', function (): void {
    it('uses the same complete assignment projection as show and role list without writing or executing', function (): void {
        $cluster = Cluster::query()->create(['name' => 'development']);
        $node = node_list_fixture('app-dev', '10.44.0.2', ['cluster_id' => $cluster->id,
            'lan_ip' => '192.0.2.2', 'tld' => 'app-dev.test', 'failed_step' => 'converge:roles', 'error_code' => 'node.converge_failed']);
        $this->caller->accessibleNodes()->attach($node);
        $expected = [];
        foreach ([RoleName::Gateway, RoleName::AppDev, RoleName::AppProd, RoleName::Metrics] as $index => $role) {
            $status = LifecycleStatus::cases()[$index];
            $assignment = $node->roles()->create(['role' => $role, 'status' => $status,
                'failed_step' => $status === LifecycleStatus::Failed ? 'converge:roles' : null,
                'error_code' => $status === LifecycleStatus::Failed ? 'role.converge_failed' : null]);
            $expected[] = ['id' => $assignment->id, 'role' => $role->value, 'status' => $status->value,
                'failed_step' => $assignment->failed_step, 'error_code' => $assignment->error_code];
        }
        DB::enableQueryLog();
        DB::flushQueryLog();

        $list = $this->getJson('/api/v1/nodes')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cluster_name', 'development')->assertJsonPath('data.0.cluster_id', $cluster->id)
            ->assertJsonPath('data.0.wireguard_ip', '10.44.0.2')->assertJsonPath('data.0.lan_ip', '192.0.2.2')
            ->assertJsonPath('data.0.failed_step', 'converge:roles')->assertJsonPath('data.0.error_code', 'node.converge_failed')
            ->assertJsonPath('data.0.assignments', $expected)->assertJsonPath('data.0.agent.state', 'not_seen');
        $show = $this->getJson("/api/v1/nodes/{$node->id}")->assertOk();
        $roles = $this->getJson("/api/v1/nodes/{$node->id}/roles")->assertOk();
        $this->getJson('/api/v1/nodes')->assertOk()->assertJsonPath('data', $list->json('data'));

        expect($show->json('data.assignments'))->toBe($expected)
            ->and($roles->json('data'))->toBe($expected)
            ->and($list->json('data.0.roles'))->toBe(array_column($expected, 'role'));
        node_list_assert_read_only_queries(DB::getQueryLog());
        Http::assertNothingSent();
        Process::assertNothingRan();
        expect(Operation::query()->count())->toBe(0);
    });

    it('keeps missing and stale agent data visible with the recorded fields', function (string $viewState, ?string $expectedState): void {
        $node = node_list_fixture('app-dev', '10.44.0.2', $viewState === 'no-agent' ? ['platform' => 'macos'] : []);
        $this->caller->accessibleNodes()->attach($node);
        $seen = CacheAgentStateView::now() - 120;
        if (in_array($viewState, ['offline', 'missing-view', 'stale-view'], true)) {
            $this->view->putNode($node->id, [], null, 1, $seen, null);
        }
        if ($viewState === 'missing-view') {
            $this->view->forgetSubscriber();
        }
        if ($viewState === 'stale-view') {
            $this->travel(AgentStateView::SubscriberSeconds + 1)->seconds();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->getJson('/api/v1/nodes')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $node->id)->assertJsonPath('data.0.wireguard_ip', '10.44.0.2')
            ->assertJsonPath('data.0.cluster_id', null)->assertJsonPath('data.0.cluster_name', null);
        if ($expectedState === null) {
            $response->assertJsonPath('data.0.agent', null);
        } else {
            $response->assertJsonPath('data.0.agent.state', $expectedState)
                ->assertJsonPath('data.0.agent.since', $expectedState === 'offline'
                    ? gmdate('Y-m-d\TH:i:s\Z', (int) $seen) : null);
        }
        node_list_assert_read_only_queries(DB::getQueryLog());
        Http::assertNothingSent();
        Process::assertNothingRan();
    })->with([
        'not yet reported' => ['not-seen', 'not_seen'],
        'no observing agent' => ['no-agent', null],
        'stale heartbeat' => ['offline', 'offline'],
        'missing Gateway view' => ['missing-view', 'unknown'],
        'stale Gateway view' => ['stale-view', 'unknown'],
    ]);

    it('returns an empty collection when an admitted collection read has no records', function (): void {
        // The HTTP boundary normally needs a recorded peer. Exercise the admitted projection with an empty store.
        $caller = $this->caller;
        $caller->delete();
        app(GatewayServingHost::class)->remember($caller);
        $request = Request::create('/api/v1/nodes');
        $request->setUserResolver(static fn (): Node => $caller);
        $request->attributes->set('orbit.request_id', 'empty-read');

        $response = app(NodesController::class)->index($request, app(ListNodesAction::class), app(NodeUpdates::class));

        expect($response->getData(true))->toBe(['data' => [], 'meta' => ['request_id' => 'empty-read']]);
    });
});

describe('node:list collection access', function (): void {
    it('refuses a caller with no grants or authority without writing', function (): void {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/nodes')->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        node_list_assert_read_only_queries(DB::getQueryLog());
        expect(Operation::query()->count())->toBe(0);
    });

    it('limits an ordinary caller to its direct grants in name then ID order', function (): void {
        $zulu = node_list_fixture('zulu', '10.44.0.2');
        $alpha = node_list_fixture('alpha', '10.44.0.3');
        node_list_fixture('hidden', '10.44.0.4');
        $this->caller->accessibleNodes()->attach([$zulu->id, $alpha->id]);

        $response = $this->getJson('/api/v1/nodes')->assertOk();

        expect(array_column($response->json('data'), 'id'))->toBe([$alpha->id, $zulu->id]);
    });

    it('lists the fleet for Gateway authority without direct target grants', function (string $authority): void {
        $gateway = node_list_fixture('gateway', '10.44.0.1');
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        $target = node_list_fixture('app-dev', '10.44.0.2');
        if ($authority === 'gateway-grant') {
            $this->caller->accessibleNodes()->attach($gateway);
        } elseif ($authority === 'gateway-peer') {
            $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
        } else {
            app(GatewayServingHost::class)->remember($this->caller);
        }

        $response = $this->getJson('/api/v1/nodes')->assertOk();

        expect(array_column($response->json('data'), 'id'))->toBe([$target->id, $gateway->id, $this->caller->id]);
    })->with(['gateway-grant', 'gateway-peer', 'serving-host']);
});

it('projects 200 recorded Nodes in under one second with bounded queries and no per-Node SQL', function (): void {
    app(GatewayServingHost::class)->remember($this->caller);
    $cluster = Cluster::query()->create(['name' => 'fleet']);
    for ($index = 1; $index < 200; $index++) {
        $node = node_list_fixture(sprintf('app-%03d', $index), "10.44.1.{$index}", ['cluster_id' => $cluster->id]);
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
        $this->view->putNode($node->id, [], null, 1, CacheAgentStateView::now(), null);
    }
    TaskVm::query()->create([
        'node_id' => $node->id, 'provider' => 'incus', 'name' => 'list-worker',
        'wireguard_ip' => '10.44.9.1', 'state' => 'ready',
    ]);
    config()->set('swamp_workers.nodes', Node::query()->pluck('id')->all());
    // Warm the request bootstrap, not the collection: the measured request still reads every record.
    $this->getJson('/api/v1/nodes')->assertOk();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $started = hrtime(true);

    $response = $this->getJson('/api/v1/nodes')->assertOk()->assertJsonCount(200, 'data');
    $seconds = (hrtime(true) - $started) / 1e9;
    $queries = DB::getQueryLog();

    expect($seconds)->toBeLessThan(1.0);
    // One collection query plus eager relationships. Admission queries do not grow with fleet size.
    expect(count(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "nodes"')
        && str_contains($query['query'], 'order by "name"'))))->toBe(1);
    expect(count($queries))->toBeLessThan(20);
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
    $response->assertJsonPath('data.0.cluster_name', 'fleet')->assertJsonPath('data.0.agent.state', 'online')
        ->assertJsonPath('data.0.swamp_worker_selected', true)
        ->assertJsonPath('data.198.swamp_worker_selected', false);
    Http::assertNothingSent();
    Process::assertNothingRan();
});

/** @param list<array{query: string, bindings: array<mixed>, time: float}> $queries */
function node_list_assert_read_only_queries(array $queries): void
{
    foreach ($queries as $query) {
        // The shared HTTP audit records reads and refusals; it must be the only possible write.
        if (str_starts_with($query['query'], 'insert into "activity_log"')) {
            continue;
        }
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
}

/** @param array<string, mixed> $attributes */
function node_list_fixture(string $name, string $ip, array $attributes = []): Node
{
    return Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => $name, 'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit',
        'platform' => 'linux', 'status' => LifecycleStatus::Active, 'architecture' => 'x86_64',
        'ssh_host_fingerprint' => 'SHA256:recorded', ...$attributes,
    ]));
}
