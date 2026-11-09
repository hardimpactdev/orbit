<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentStateView;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

function showRecordedNode(string $name, string $ip, array $extra = []): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit',
        ...$extra,
    ]));
}

function recordedNodeSnapshot(): array
{
    return collect(['nodes', 'node_roles', 'node_access', 'clusters', 'operations'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

describe('node:show recorded read', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
        Process::fake();
        $this->caller = showRecordedNode('caller', '10.44.0.9');
        $this->node = showRecordedNode('retained', '10.44.0.2', [
            'ssh_host_fingerprint' => 'SHA256:recorded',
        ]);
        $this->caller->accessibleNodes()->attach($this->node);
        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
        $this->view = new CacheAgentStateView(Cache::store('array'));
        $this->app->instance(AgentStateView::class, $this->view);
        $this->travelTo(now()->startOfSecond());
    });

    it('returns named detail and every role assignment identically across the three reads without writes or outbound calls', function (): void {
        $cluster = Cluster::query()->create(['name' => 'development']);
        $this->node->updateQuietly([
            'cluster_id' => $cluster->id, 'status' => 'failed', 'failed_step' => 'converge:apps',
            'error_code' => 'node.enrollment_failed', 'architecture' => 'x86_64', 'lan_ip' => '192.168.1.2',
            'wireguard_public_key' => 'recorded-key',
            'dns_server_override' => '192.168.1.1', 'tld' => 'dev.orbit',
            'settings' => ['apps' => ['path' => '/srv/apps']],
        ]);
        $assignments = [];
        foreach (['app-dev' => 'active', 'app-prod' => 'provisioning', 'database' => 'failed', 'vpn' => 'removing'] as $role => $status) {
            $fields = [
                'role' => $role, 'status' => $status,
                'failed_step' => $status === 'failed' ? 'converge:database' : null,
                'error_code' => $status === 'failed' ? 'node.role_failed' : null,
            ];
            $assignment = $this->node->roles()->create($fields);
            $assignments[$role] = ['id' => $assignment->id, ...$fields];
        }
        $expectedRoles = array_map(fn (string $role): array => $assignments[$role], ['vpn', 'app-dev', 'app-prod', 'database']);
        $this->node->accessibleNodes()->attach($this->caller);
        $before = recordedNodeSnapshot();
        $this->getJson('/api/v1/nodes/'.$this->node->id.'/roles')->assertOk()->assertJsonPath('data', $expectedRoles);
        $list = $this->getJson('/api/v1/nodes')->assertOk()->json('data');
        expect(collect($list)->firstWhere('id', $this->node->id)['assignments'])->toBe($expectedRoles);
        for ($repeat = 0; $repeat < 2; $repeat++) {
            $this->getJson('/api/v1/nodes/'.$this->node->id)->assertOk()
                ->assertJsonPath('data.id', $this->node->id)
                ->assertJsonPath('data.name', 'retained')
                ->assertJsonPath('data.cluster_id', $cluster->id)
                ->assertJsonPath('data.cluster_name', 'development')
                ->assertJsonPath('data.roles', array_column($expectedRoles, 'role'))
                ->assertJsonPath('data.assignments', $expectedRoles)
                ->assertJsonPath('data.status', 'failed')
                ->assertJsonPath('data.failed_step', 'converge:apps')
                ->assertJsonPath('data.error_code', 'node.enrollment_failed')
                ->assertJsonPath('data.lan_ip', '192.168.1.2')
                ->assertJsonPath('data.wireguard_public_key', 'recorded-key')
                ->assertJsonMissingPath('data.wireguard_endpoint_override')
                ->assertJsonPath('data.dns_server_override', '192.168.1.1')
                ->assertJsonPath('data.settings.apps.path', '/srv/apps')
                ->assertJsonPath('data.access.can_access.0.name', 'caller')
                ->assertJsonPath('data.access.accessible_by.0.name', 'caller');
        }
        expect(recordedNodeSnapshot())->toBe($before);
        Http::assertNothingSent();
        Process::assertNothingRan();
    });

    it('preserves absent detail and an unmanaged agent as null', function (): void {
        $this->node->updateQuietly(['wireguard_ip' => null, 'ssh_host_fingerprint' => null]);
        $before = recordedNodeSnapshot();
        $this->getJson('/api/v1/nodes/'.$this->node->id)->assertOk()
            ->assertJsonPath('data.cluster_name', null)->assertJsonPath('data.agent', null)
            ->assertJsonPath('data.settings', null)->assertJsonPath('data.lan_ip', null)
            ->assertJsonPath('data.roles', [])->assertJsonPath('data.assignments', []);
        expect(recordedNodeSnapshot())->toBe($before);
        Http::assertNothingSent();
        Process::assertNothingRan();
    });

    it('projects recorded agent freshness including the exact boundary', function (string $state, ?float $age, bool $subscriber): void {
        if ($subscriber) {
            $this->view->putSubscriber(true, true, 1);
        }
        if ($age !== null) {
            $this->view->putNode($this->node->id, [], null, 1, CacheAgentStateView::now() - $age, null);
            if ($age > CacheAgentStateView::NodeTtlSeconds) {
                $this->view->forgetNode($this->node->id);
            }
        }
        $before = recordedNodeSnapshot();
        $this->getJson('/api/v1/nodes/'.$this->node->id)->assertOk()
            ->assertJsonPath('data.agent.state', $state)
            ->assertJsonPath('data.agent.since', $state === 'offline' ? now()->subSeconds((int) $age)->utc()->format('Y-m-d\TH:i:s\Z') : null);
        expect(recordedNodeSnapshot())->toBe($before);
        Http::assertNothingSent();
        Process::assertNothingRan();
    })->with([
        'fresh' => ['online', 0.0, true], 'boundary' => ['online', 15.0, true],
        'stale' => ['offline', 16.0, true], 'expired' => ['offline', 120.0, true],
        'never seen' => ['not_seen', null, true], 'unavailable' => ['unknown', 0.0, false],
    ]);

    it('reads the current recorded state rather than a cached successful result', function (): void {
        $this->getJson('/api/v1/nodes/'.$this->node->id)->assertOk()->assertJsonPath('data.name', 'retained');
        $this->node->updateQuietly(['name' => 'renamed', 'settings' => ['apps' => ['path' => '/srv/new-apps']]]);
        $role = $this->node->roles()->create(['role' => 'app-dev', 'status' => 'removing']);
        $before = recordedNodeSnapshot();
        $this->getJson('/api/v1/nodes/'.$this->node->id)->assertOk()
            ->assertJsonPath('data.name', 'renamed')->assertJsonPath('data.settings.apps.path', '/srv/new-apps')
            ->assertJsonPath('data.assignments.0.id', $role->id)->assertJsonPath('data.assignments.0.status', 'removing');
        expect(recordedNodeSnapshot())->toBe($before);
        Http::assertNothingSent();
        Process::assertNothingRan();
    });

    it('refuses a caller without disclosing detail and leaves records unchanged', function (): void {
        $this->caller->accessibleNodes()->detach();
        $before = recordedNodeSnapshot();
        $this->getJson('/api/v1/nodes/'.$this->node->id)->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required')->assertJsonMissingPath('data');
        expect(recordedNodeSnapshot())->toBe($before);
        Http::assertNothingSent();
        Process::assertNothingRan();
    });

    it('returns http.404 for an unknown Node without changing records', function (): void {
        $before = recordedNodeSnapshot();
        $this->getJson('/api/v1/nodes/2147483647')->assertNotFound()->assertJsonPath('error.code', 'http.404');
        expect(recordedNodeSnapshot())->toBe($before);
        Http::assertNothingSent();
        Process::assertNothingRan();
    });
});
