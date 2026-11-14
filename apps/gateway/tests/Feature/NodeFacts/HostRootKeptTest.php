<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Node;
use App\Models\NodeFact;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** The `host` report the inner Gateway's agent sent on copy 2743 (run1) once its disks were read. */
function host_root_seed(): array
{
    return ['os' => 'linux', 'arch' => 'x86_64', 'instance_bridge' => null, 'filesystems' => [
        ['mount' => '/', 'size' => 32360497152, 'free' => 10468982784, 'observed_at' => '2026-11-14T05:30:28.101Z', 'stale' => false],
        ['mount' => '/dev/.incus-systemd-credentials', 'size' => 32360497152, 'free' => 10468982784, 'observed_at' => '2026-11-14T05:30:28.101Z', 'stale' => false],
        ['mount' => '/var/tmp', 'size' => 32360497152, 'free' => 10468982784, 'observed_at' => '2026-11-14T05:30:28.101Z', 'stale' => false],
    ]];
}

function host_root_report(Node $node, array $data, string $at): void
{
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->withToken('agent-secret')
        ->postJson('/api/v1/agent/facts', ['kind' => 'host', 'observed_at' => $at, 'data' => $data])
        ->assertNoContent();
}

/** @return list<string> the codes of the Node family's findings */
function host_root_doctor(Node $node): array
{
    $report = app(FactDoctor::class)->report([$node], [DoctorFamily::Node])->toArray();

    return array_column($report['nodes'][0]['families'][0]['issues'], 'code');
}

function host_root_stored(Node $node): array
{
    return NodeFact::query()->where('node_id', $node->id)->where('kind', 'host')->sole()->data;
}

/** Moves the clock; the agent stays online, as the agent view hears it every 5 seconds. */
function host_root_at(Node $node, string $at): void
{
    CarbonImmutable::setTestNow($at);
    $view = app(CacheAgentStateView::class);
    $view->putSubscriber(true, true, 1);
    $view->putNode($node->id, [], 'absent', 1, CacheAgentStateView::now(), $at);
}

beforeEach(function (): void {
    $this->node = Node::withoutEvents(function (): Node {
        $node = Node::query()->create([
            'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.45.0.1',
            'wireguard_ip' => '10.45.0.1', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint',
        ]);
        $node->forceFill(['agent_secret_hash' => hash('sha256', 'agent-secret')])->save();

        return $node;
    });
    host_root_at($this->node, '2026-11-14T06:21:50Z');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('keeps the stored root reading when the first report after an agent start has no reading for it', function (array $filesystems): void {
    // The seed of a copy holds a good row from the last host report before its snapshot.
    host_root_report($this->node, host_root_seed(), '2026-11-14T05:30:28.101Z');
    expect(host_root_doctor($this->node))->toBe([]);

    // Run1's first report: the disk sampler had no reading yet, so `/` is missing.
    host_root_report($this->node, ['os' => 'linux', 'arch' => 'x86_64', 'instance_bridge' => null, 'filesystems' => $filesystems], '2026-11-14T06:21:50.758Z');

    $stored = host_root_stored($this->node);
    expect($stored['filesystems'][0])->toBe([
        'mount' => '/', 'size' => 32360497152, 'free' => 10468982784,
        'observed_at' => '2026-11-14T05:30:28Z', 'kept_since' => '2026-11-14T06:21:50Z',
    ])->and(array_column($stored['filesystems'], 'mount'))->toBe(['/', ...array_column($filesystems, 'mount')])
        ->and(host_root_doctor($this->node))->toBe([]);
})->with([
    'no filesystem read yet' => [[]],
    'root read timed out' => [[['mount' => '/var/tmp', 'size' => 32360497152, 'free' => 10468982784, 'observed_at' => '2026-11-14T06:21:50.700Z', 'stale' => false]]],
]);

it('stops trusting a kept root reading after three host intervals and keeps its first times on a retry', function (): void {
    host_root_report($this->node, host_root_seed(), '2026-11-14T05:30:28.101Z');
    $first = ['os' => 'linux', 'arch' => 'x86_64', 'instance_bridge' => null, 'filesystems' => []];
    host_root_report($this->node, $first, '2026-11-14T06:21:50.758Z');

    // The same report sent again, and a later one still without `/`, do not renew the kept reading.
    host_root_at($this->node, '2026-11-14T06:23:50Z');
    host_root_report($this->node, $first, '2026-11-14T06:21:50.758Z');
    host_root_report($this->node, $first, '2026-11-14T06:23:50.300Z');
    expect(host_root_stored($this->node)['filesystems'][0]['kept_since'])->toBe('2026-11-14T06:21:50Z')
        ->and(host_root_doctor($this->node))->toBe([]);

    host_root_at($this->node, '2026-11-14T06:24:51Z');
    expect(host_root_doctor($this->node))->toBe(['node.inspection_failed']);

    // A new reading of `/` replaces the kept one and Doctor trusts it again.
    host_root_report($this->node, host_root_seed(), '2026-11-14T06:24:50.300Z');
    expect(host_root_stored($this->node)['filesystems'][0])->not->toHaveKey('kept_since')
        ->and(host_root_doctor($this->node))->toBe([]);
});

it('leaves a Node that never reported its root filesystem unverifiable', function (): void {
    host_root_report($this->node, ['os' => 'linux', 'arch' => 'x86_64', 'instance_bridge' => null, 'filesystems' => []], '2026-11-14T06:21:50.758Z');

    expect(host_root_stored($this->node)['filesystems'])->toBe([])
        ->and(host_root_doctor($this->node))->toBe(['node.inspection_failed']);
});

it('judges a low disk on the kept root reading', function (): void {
    $seed = host_root_seed();
    $seed['filesystems'][0]['free'] = 1073741824;
    host_root_report($this->node, $seed, '2026-11-14T05:30:28.101Z');
    host_root_report($this->node, ['os' => 'linux', 'arch' => 'x86_64', 'instance_bridge' => null, 'filesystems' => []], '2026-11-14T06:21:50.758Z');

    expect(host_root_doctor($this->node))->toBe(['node.disk_low']);
});
