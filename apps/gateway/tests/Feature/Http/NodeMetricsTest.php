<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

function metrics_read_node(string $name, string $ip): Node
{
    return Node::withoutEvents(function () use ($name, $ip): Node {
        $node = Node::query()->create([
            'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip,
            'wireguard_ip' => $ip, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint',
        ]);
        $node->forceFill(['agent_secret_hash' => hash('sha256', 'agent-secret')])->save();

        return $node;
    });
}

function metrics_read_snapshot(): array
{
    return [
        'cores' => [0.12, 0.34, 0.08, 0.21],
        'memory' => ['used' => 3435973836, 'total' => 8589934592],
        'swap' => ['used' => 0, 'total' => 2147483648],
        'load' => ['one' => 0.52, 'five' => 0.61, 'fifteen' => 0.58],
        'uptime_seconds' => 1053784,
        'pressure' => ['cpu' => ['some_avg10' => 0.4], 'memory' => ['some_avg10' => 0], 'io' => ['some_avg10' => 1.1]],
        'disks' => [['mount' => '/', 'used' => 6442450944, 'total' => 85899345920, 'observed_at' => '2026-11-06T09:59:59Z', 'stale' => false]],
    ];
}

function metrics_read_report(Node $node, array $snapshot, string $at = '2026-11-06T10:00:00Z'): void
{
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->withToken('agent-secret')
        ->postJson('/api/v1/agent/facts', ['kind' => 'metrics', 'observed_at' => $at, 'data' => $snapshot])
        ->assertNoContent();
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $this->caller = metrics_read_node('caller', '10.44.0.9');
    $this->node = metrics_read_node('app-dev', '10.44.0.2');
    $this->caller->accessibleNodes()->attach($this->node);
    $this->view = app(CacheAgentStateView::class);
    $this->view->putSubscriber(true, true, 1);
    $this->view->putNode($this->node->id, [], 'absent', 1, CacheAgentStateView::now(), '2026-11-06T10:00:00Z');
});

afterEach(function (): void {
    Http::assertNothingSent();
});

describe('GET /api/v1/nodes/{node}/metrics', function (): void {
    it('reads only the recorded snapshot and liveness without changing fleet or snapshot records', function (): void {
        metrics_read_report($this->node, metrics_read_snapshot());
        $before = NodeFact::query()->get()->toArray();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            // The shared request audit is allowed; the read must not mutate fleet or metrics state.
            if (preg_match('/^\s*(insert|update|delete|replace)/i', $query->sql) === 1 && ! str_contains($query->sql, '"activity_log"')) {
                $writes[] = $query->sql;
            }
        });

        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->getJson('/api/v1/nodes/'.$this->node->id.'/metrics')->assertOk()
            ->assertJsonPath('data', [
                'node_id' => $this->node->id, 'node_name' => 'app-dev',
                ...metrics_read_snapshot(), 'observed_at' => '2026-11-06T10:00:00Z',
                'agent' => ['state' => 'online', 'since' => null], 'possibly_outdated' => false,
            ]);

        expect($writes)->toBe([])->and(NodeFact::query()->get()->toArray())->toBe($before);
        expect(Operation::query()->count())->toBe(0);
    });

    it('keeps healthy, stale and never-observed mounts separate through report admission and reads', function (): void {
        $snapshot = metrics_read_snapshot();
        $snapshot['disks'][] = ['mount' => '/slow', 'used' => 123, 'total' => 456, 'observed_at' => '2026-11-06T09:58:00Z', 'stale' => true];
        $snapshot['disks'][] = ['mount' => '/hung', 'used' => null, 'total' => null, 'observed_at' => null, 'stale' => true];
        metrics_read_report($this->node, $snapshot);

        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->getJson('/api/v1/nodes/'.$this->node->id.'/metrics')->assertOk()
            ->assertJsonPath('data.disks', $snapshot['disks'])
            ->assertJsonPath('data.possibly_outdated', false);
        expect(NodeFact::query()->firstOrFail()->data['disks'])->toBe($snapshot['disks']);
    });

    it('retains the original snapshot after the disposable view expires', function (): void {
        metrics_read_report($this->node, metrics_read_snapshot());
        $this->view->putNode($this->node->id, [], 'absent', 2, CacheAgentStateView::now() - 90, null);
        $seen = $this->view->lastSeen($this->node->id);
        $this->view->forgetNode($this->node->id);

        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->getJson('/api/v1/nodes/'.$this->node->id.'/metrics')->assertOk()
            ->assertJsonPath('data.observed_at', '2026-11-06T10:00:00Z')
            ->assertJsonPath('data.memory', metrics_read_snapshot()['memory'])
            ->assertJsonPath('data.agent', ['state' => 'offline', 'since' => CarbonImmutable::createFromTimestamp($seen)->utc()->format('Y-m-d\TH:i:s\Z')])
            ->assertJsonPath('data.possibly_outdated', true);
    });

    it('keeps the shared not-seen meaning when no presence has ever arrived', function (): void {
        metrics_read_report($this->node, metrics_read_snapshot());
        $neverSeen = metrics_read_node('never-seen', '10.44.0.3');
        $this->caller->accessibleNodes()->attach($neverSeen);
        metrics_read_report($neverSeen, metrics_read_snapshot());
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->getJson('/api/v1/nodes/'.$neverSeen->id.'/metrics')->assertOk()
            ->assertJsonPath('data.agent', ['state' => 'not_seen', 'since' => null])
            ->assertJsonPath('data.possibly_outdated', true);
    });

    it('records a new observation time even if the reported values are unchanged', function (): void {
        metrics_read_report($this->node, metrics_read_snapshot());
        metrics_read_report($this->node, metrics_read_snapshot(), '2026-11-06T10:01:00Z');
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->getJson('/api/v1/nodes/'.$this->node->id.'/metrics')->assertOk()
            ->assertJsonPath('data.observed_at', '2026-11-06T10:01:00Z');
        expect(NodeFact::query()->count())->toBe(1);
    });

    it('validates metrics reports and retains the previous good reading', function (string $field, mixed $value): void {
        metrics_read_report($this->node, metrics_read_snapshot());
        $before = NodeFact::query()->firstOrFail()->toArray();
        $data = metrics_read_snapshot();
        data_set($data, $field, $value);
        $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])->withToken('agent-secret')
            ->postJson('/api/v1/agent/facts', ['kind' => 'metrics', 'observed_at' => '2026-11-06T10:01:00Z', 'data' => $data])
            ->assertUnprocessable();
        expect(NodeFact::query()->firstOrFail()->toArray())->toBe($before);
    })->with([
        'busy ratio' => ['cores.0', 1.5], 'no cores' => ['cores', []], 'negative bytes' => ['swap.used', -1],
        'used exceeds total' => ['memory.used', 99999999999], 'missing load' => ['load.one', null],
        'negative uptime' => ['uptime_seconds', -1], 'pressure range' => ['pressure.io.some_avg10', 101],
        'disk mount' => ['disks.0.mount', 'relative'], 'disk used exceeds total' => ['disks.0.used', 99999999999],
        'unknown pressure key' => ['pressure.cpu.extra', 'invalid'],
    ]);

    it('labels a retained snapshot when subscriber health is unknown', function (): void {
        metrics_read_report($this->node, metrics_read_snapshot());
        $this->view->forgetSubscriber();
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->getJson('/api/v1/nodes/'.$this->node->id.'/metrics')->assertOk()
            ->assertJsonPath('data.agent.state', 'unknown')->assertJsonPath('data.possibly_outdated', true);
    });

    it('reads retained snapshots even when a Node is no longer eligible for observation', function (array $attributes): void {
        metrics_read_report($this->node, metrics_read_snapshot());
        Node::withoutEvents(fn () => $this->node->forceFill($attributes)->save());
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->getJson('/api/v1/nodes/'.$this->node->id.'/metrics')->assertOk()
            ->assertJsonPath('data.agent', null)->assertJsonPath('data.possibly_outdated', true);
    })->with(['inactive' => [['status' => 'failed']], 'no WireGuard' => [['wireguard_ip' => null]]]);

    it('refuses a missing snapshot instead of returning zeros', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->getJson('/api/v1/nodes/'.$this->node->id.'/metrics')->assertStatus(422)
            ->assertJsonPath('error.code', 'node.metrics_unavailable');
        expect(NodeFact::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
    });

    it('refuses an unknown Node', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->getJson('/api/v1/nodes/'.($this->node->id + 1000).'/metrics')->assertNotFound()
            ->assertJsonPath('error.code', 'http.404');
    });

    it('authorizes before looking for a snapshot', function (): void {
        $this->caller->accessibleNodes()->detach();
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->getJson('/api/v1/nodes/'.$this->node->id.'/metrics')->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required');
        expect(NodeFact::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
    });

    it('accepts Gateway authority without a target grant', function (bool $viaGrant): void {
        $this->caller->accessibleNodes()->detach();
        $gateway = $viaGrant ? metrics_read_node('gateway', '10.44.0.1') : $this->caller;
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        if ($viaGrant) {
            $this->caller->accessibleNodes()->attach($gateway);
        }
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->getJson('/api/v1/nodes/'.$this->node->id.'/metrics')->assertStatus(422)
            ->assertJsonPath('error.code', 'node.metrics_unavailable');
    })->with(['Gateway caller' => false, 'Gateway grant' => true]);

    it('returns replacement metrics on a repeat and leaves the report untouched', function (): void {
        metrics_read_report($this->node, metrics_read_snapshot());
        $uri = '/api/v1/nodes/'.$this->node->id.'/metrics';
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])->getJson($uri)->assertOk()
            ->assertJsonPath('data.uptime_seconds', 1053784);
        $snapshot = array_replace(metrics_read_snapshot(), ['uptime_seconds' => 1053844]);
        metrics_read_report($this->node, $snapshot, '2026-11-06T10:01:00Z');
        $before = NodeFact::query()->get()->toArray();
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])->getJson($uri)->assertOk()
            ->assertJsonPath('data.uptime_seconds', 1053844)->assertJsonPath('data.observed_at', '2026-11-06T10:01:00Z');
        expect(NodeFact::query()->get()->toArray())->toBe($before)->and(Operation::query()->count())->toBe(0);
    });
});
