<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Node;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->node = Node::withoutEvents(function (): Node {
        $node = Node::query()->create([
            'name' => 'vpn', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.1',
            'wireguard_ip' => '10.44.0.1', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint',
        ]);
        $node->forceFill(['agent_secret_hash' => hash('sha256', 'agent-secret')])->save();

        return $node;
    });
    // The agent is online: the agent view heard it just now.
    CarbonImmutable::setTestNow('2026-11-20T09:00:00Z');
    $view = app(CacheAgentStateView::class);
    $view->putSubscriber(true, true, 1);
    $view->putNode($this->node->id, [], 'absent', 1, CacheAgentStateView::now(), '2026-11-20T09:00:00Z');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return list<string> the codes of the Node family's findings after a `host` report with this clock state */
function host_clock_doctor(Node $node, mixed $clock): array
{
    $data = ['os' => 'linux', 'arch' => 'x86_64', 'instance_bridge' => null, 'filesystems' => [
        ['mount' => '/', 'size' => 32360497152, 'free' => 10468982784, 'observed_at' => now()->toIso8601ZuluString(), 'stale' => false],
    ]];
    if ($clock !== 'absent') {
        $data['clock_synchronized'] = $clock;
    }
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->withToken('agent-secret')
        ->postJson('/api/v1/agent/facts', ['kind' => 'host', 'observed_at' => now()->toIso8601ZuluString(), 'data' => $data])
        ->assertNoContent();
    $report = app(FactDoctor::class)->report([$node], [DoctorFamily::Node])->toArray();

    return array_column($report['nodes'][0]['families'][0]['issues'], 'code');
}

it('warns about a Node whose clock follows no time source, because it would refuse signed orders', function (): void {
    expect(host_clock_doctor($this->node, false))->toContain('node.clock_unsynchronized');
});

it('stays quiet for a synchronized clock and for an agent that cannot say', function (mixed $clock): void {
    expect(host_clock_doctor($this->node, $clock))->not->toContain('node.clock_unsynchronized')
        ->not->toContain('node.inspection_failed');
})->with(['synchronized' => true, 'older agent' => 'absent', 'a Mac' => null]);
