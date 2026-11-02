<?php

declare(strict_types=1);

use App\Data\Nodes\NodeData;
use App\Domain\NodeFacts\NodeAgentLiveness;
use App\Domain\NodeFacts\NodeAgentState;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\NodeFact;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

describe('agent facts and liveness', function (): void {
    beforeEach(function (): void {
        $this->node = agent_view_node('facts-node', '10.44.0.51');
        $this->node->forceFill(['agent_secret_hash' => hash('sha256', 'facts-secret'), 'ssh_host_fingerprint' => 'SHA256:test'])->save();
        $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])->withToken('facts-secret');
    });
    afterEach(fn () => Carbon::setTestNow());

    $host = static fn (int $free = 40): array => ['kind' => 'host', 'observed_at' => '2026-11-02T18:00:00Z',
        'data' => ['os' => 'linux', 'arch' => 'x86_64', 'filesystems' => [['mount' => '/', 'size' => 100, 'free' => $free]]]];

    it('stores a changed fact once and writes nothing for an unchanged report', function () use ($host): void {
        $this->postJson('/api/v1/agent/facts', $host())->assertNoContent();
        $first = NodeFact::query()->sole();
        expect($first->data['filesystems'][0]['free'])->toBe(40)->and($first->observed_at->toIso8601ZuluString())->toBe('2026-11-02T18:00:00Z');

        $writes = 0;
        $transactions = 0;
        Event::listen(TransactionBeginning::class, static function () use (&$transactions): void {
            $transactions++;
        });
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
                $writes++;
            }
        });
        $this->postJson('/api/v1/agent/facts', $host())->assertNoContent();
        expect($writes)->toBe(0)->and($transactions)->toBe(0);

        $this->postJson('/api/v1/agent/facts', $host(39))->assertNoContent();
        expect($writes)->toBe(1)->and($transactions)->toBe(1)->and(NodeFact::query()->sole()->data['filesystems'][0]['free'])->toBe(39);
    });

    it('refuses an unknown kind, malformed data and a body over 1 MiB', function () use ($host): void {
        $this->postJson('/api/v1/agent/facts', [...$host(), 'kind' => 'secrets'])->assertUnprocessable();
        $this->postJson('/api/v1/agent/facts', [...$host(), 'data' => [1, 2]])->assertUnprocessable();
        $this->postJson('/api/v1/agent/facts', ['kind' => 'units', 'observed_at' => '2026-11-02T18:00:00Z',
            'data' => ['docker' => 'available', 'units' => [['name' => 'a b', 'runtime' => 'systemd', 'state' => 'active']]]])->assertUnprocessable();
        $big = $host();
        $big['data']['os'] = str_repeat('x', 1_048_577);
        $this->postJson('/api/v1/agent/facts', $big)->assertStatus(413)->assertJsonPath('error.code', 'agent.fact_too_large');
        expect(NodeFact::query()->count())->toBe(0);
    });

    it('refuses an unknown peer and a wrong secret', function () use ($host): void {
        $this->withToken('wrong')->postJson('/api/v1/agent/facts', $host())->assertForbidden()->assertJsonPath('error.code', 'agent.secret_invalid');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/v1/agent/facts', $host())
            ->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
    });

    it('names the role services the agent watches', function (): void {
        $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
        $this->node->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);

        $this->getJson('/api/v1/agent/watch')->assertOk()
            ->assertJsonPath('data.services', ['caddy.service', 'docker.service'])->assertJsonPath('data.workspaces', []);
    });

    it('reports a Node online, then offline since its last event, and unknown without a subscriber', function (): void {
        $liveness = app(NodeAgentLiveness::class);
        Carbon::setTestNow('2026-11-02 18:00:00');
        app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
        expect($liveness->for($this->node)?->state)->toBe(NodeAgentState::NotSeen);

        seed_agent_view($this->node->id, []);
        expect($liveness->for($this->node)?->toArray())->toBe(['state' => 'online', 'since' => null]);

        Carbon::setTestNow('2026-11-02 18:05:00');
        app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
        expect($liveness->for($this->node)?->toArray())->toBe(['state' => 'offline', 'since' => '2026-11-02T18:00:00Z'])
            ->and(NodeData::fromModel($this->node)->toArray()['agent'])
            ->toBe(['state' => 'offline', 'since' => '2026-11-02T18:00:00Z']);

        Carbon::setTestNow('2026-11-02 18:06:00');
        expect($liveness->for($this->node)?->state)->toBe(NodeAgentState::Unknown);
    });
});
