<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallInspectionInput;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Carbon;

/** @param array<string, mixed> $data */
function doctor_fact(Node $node, NodeFactKind $kind, array $data): void
{
    NodeFact::query()->updateOrCreate(['node_id' => $node->id, 'kind' => $kind, 'subject' => ''],
        ['digest' => hash('sha256', json_encode($data)), 'data' => $data, 'observed_at' => '2026-11-03T02:00:00Z', 'received_at' => '2026-11-03T02:00:01Z']);
}

/** @param array<string, string> $states */
function doctor_units(Node $node, array $states = [], ?array $awake = []): void
{
    $units = [];
    foreach (['caddy.service' => 'active', 'docker.service' => 'active', ...$states] as $name => $state) {
        [$active, $sub] = explode('/', $state.'/'.($state === 'active' ? 'running' : 'dead'));
        $units[] = ['name' => $name, 'runtime' => 'systemd', 'state' => $active, 'load' => 'loaded', 'sub' => $sub];
    }
    doctor_fact($node, NodeFactKind::Units, ['docker' => 'available', 'units' => $units, ...($awake === null ? [] : ['awake' => $awake])]);
}

/** The `ufw` fact that holds exactly the rules Orbit intends on the Node. */
function doctor_ufw(Node $node, bool $enabled = true, int $drop = 0): void
{
    $rules = array_map(static fn (array $rule): array => ['action' => $rule['shape']['action'], 'protocol' => $rule['shape']['protocol'], 'port' => $rule['shape']['port'],
        'destination' => $rule['shape']['destination'] === 'any' ? '0.0.0.0/0' : $rule['shape']['destination'], 'source_port' => 'any',
        'source' => $rule['shape']['source'] === 'any' ? '0.0.0.0/0' : $rule['shape']['source'], 'direction' => $rule['shape']['direction'],
        'in_interface' => $rule['shape']['in_interface'], 'out_interface' => $rule['shape']['out_interface'], 'family' => $rule['shape']['family'] ?? 'v4',
        'comment' => $rule['shape']['comment'], 'app' => false], app(FirewallInspectionInput::class)->expected($node));
    doctor_fact($node, NodeFactKind::Ufw, ['installed' => true, 'enabled' => $enabled, 'rules' => array_slice($rules, $drop)]);
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-03 02:00:00');
    $this->node = agent_view_node('doctor-node', '10.44.0.61');
    $this->node->forceFill(['architecture' => 'x86_64', 'ssh_host_fingerprint' => 'SHA256:doctor', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAdoctor'])->save();
    NodeRole::query()->create(['node_id' => $this->node->id, 'role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
    seed_agent_view($this->node->id, []);
    doctor_fact($this->node, NodeFactKind::Host, ['os' => 'linux', 'arch' => 'x86_64', 'filesystems' => [['mount' => '/', 'size' => 100 * 1_073_741_824, 'free' => 40 * 1_073_741_824]]]);
    doctor_units($this->node);
    doctor_ufw($this->node);
    $this->doctor = fn (array $families = ['node', 'role', 'process', 'firewall']): array => $this->postJson('/api/v1/doctor', ['node_id' => $this->node->id, 'families' => $families])
        ->assertOk()->json('data');
});
afterEach(fn () => Carbon::setTestNow());

/** @return list<string> */
function doctor_codes(array $report): array
{
    return collect($report['nodes'][0]['families'])->flatMap(fn (array $family): array => array_column($family['issues'], 'code'))->all();
}

it('answers from the facts with the agent first, starting no run', function (): void {
    $report = ($this->doctor)();

    expect($report['healthy'])->toBeTrue()->and($report['nodes'][0]['agent'])->toBe(['state' => 'online', 'since' => null])
        ->and(collect($report['nodes'][0]['families'])->pluck('checked', 'family')->all())->toBe(['node' => 3, 'role' => 2, 'process' => 0, 'firewall' => 1])
        ->and(Operation::query()->count())->toBe(0);
});

it('reports a stopped role service and a missing or disabled firewall from the facts', function (): void {
    doctor_units($this->node, ['caddy.service' => 'inactive']);
    doctor_ufw($this->node, drop: 1);
    $report = ($this->doctor)(['role', 'firewall']);
    $role = collect($report['nodes'][0]['families'])->firstWhere('family', 'role');

    expect(doctor_codes($report))->toBe(['role.services_inactive', 'firewall.rule_missing'])
        ->and($role['issues'][0]['observed'])->toBe('caddy.service: loaded/inactive');
    doctor_ufw($this->node, enabled: false);
    expect(doctor_codes(($this->doctor)(['firewall'])))->toBe(['firewall.backend_inactive']);
});

it('reports low disk, and an offline agent with the time it went silent', function (): void {
    doctor_fact($this->node, NodeFactKind::Host, ['os' => 'linux', 'arch' => 'amd64', 'filesystems' => [['mount' => '/', 'size' => 100 * 1_073_741_824, 'free' => 1_073_741_824]]]);
    Carbon::setTestNow('2026-11-03 02:05:00');
    app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
    $report = ($this->doctor)(['node']);

    expect($report['nodes'][0]['agent'])->toBe(['state' => 'offline', 'since' => '2026-11-03T02:00:00Z'])
        ->and(doctor_codes($report))->toBe(['node.disk_low', 'node.agent_view_stale'])
        ->and($report['nodes'][0]['families'][0]['issues'][1]['observed'])->toBe('silent since 2026-11-03T02:00:00Z');
});

it('marks each family unverifiable until its fact arrives, and checks nothing for the other families', function (): void {
    NodeFact::query()->delete();
    $report = ($this->doctor)(['node', 'role', 'firewall', 'route']);
    $families = collect($report['nodes'][0]['families'])->keyBy('family');

    expect($families->map(fn (array $family): string => $family['status'])->all())->toBe(['node' => 'unverifiable', 'role' => 'unverifiable', 'firewall' => 'unverifiable', 'route' => 'healthy'])
        ->and(doctor_codes($report))->toBe(['node.inspection_failed', 'role.inspection_failed', 'firewall.inspection_failed'])
        ->and($families['route']['checked'])->toBe(0);
});

it('reports a crash-looping Process, and a hibernating one only when its awake marker exists', function (): void {
    $web = agent_view_instance_process($this->node, 'web');
    $unit = "orbit-process-{$web->id}-web";
    doctor_units($this->node, [$unit => 'activating/auto-restart']);
    expect(doctor_codes(($this->doctor)(['process'])))->toBe(['process.crash_loop']);

    // The app-dev Node hibernates its Instances: stopped without an awake marker is asleep, stopped with one is down.
    $marker = RuntimeHibernation::awakePath(RuntimeHibernation::key($web->owner_id));
    doctor_units($this->node, [$unit => 'inactive']);
    expect(doctor_codes(($this->doctor)(['process'])))->toBe([]);
    doctor_units($this->node, [$unit => 'inactive'], [$marker]);
    expect(doctor_codes(($this->doctor)(['process'])))->toBe(['process.state_mismatch']);
});

it('cannot tell a missing Process unit from an unloaded one in a report from an agent before 0.10.0', function (): void {
    agent_view_instance_process($this->node, 'web');
    doctor_units($this->node, awake: null);
    expect(doctor_codes(($this->doctor)(['process'])))->toBe(['process.inspection_failed']);
    doctor_units($this->node);
    expect(doctor_codes(($this->doctor)(['process'])))->toBe(['process.runtime_missing']);
});
