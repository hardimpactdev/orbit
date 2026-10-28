<?php

declare(strict_types=1);

use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** A rollout with one visit row for the Node. */
function nodeUpdatingVisit(Node $node, FleetRolloutStatus $status, ?Carbon $startedAt, ?Carbon $finishedAt = null): FleetRolloutNode
{
    $rollout = FleetRollout::query()->create([
        'commit' => str_repeat('a', 40),
        'status' => $status,
        'desired_state' => [],
        'order' => [$node->id],
        'started_at' => now()->subHour(),
    ]);

    if ($startedAt !== null && $finishedAt === null) {
        Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id, 'status' => 'running', 'input' => []]);
    }

    return FleetRolloutNode::query()->create([
        'fleet_rollout_id' => $rollout->id,
        'node_id' => $node->id,
        'node_name' => $node->name,
        'position' => 1,
        'outcome' => FleetNodeOutcome::Pending,
        'attempts' => 1,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
    ]);
}

function nodeUpdatingRelease(string $outcome): GatewayRelease
{
    return GatewayRelease::query()->create([
        'requested' => str_repeat('b', 40),
        'trigger' => 'auto',
        'outcome' => $outcome,
        'phases' => [],
        'duration_ms' => 0,
    ]);
}

describe('Node updating state', function (): void {
    beforeEach(function (): void {
        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->gateway = $this->markAsGateway(Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.2',
            'wireguard_ip' => '10.44.0.2',
        ]));
        $this->worker = Node::query()->create([
            'name' => 'worker',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.3',
            'wireguard_ip' => '10.44.0.3',
        ]);
        NodeRole::query()->create(['node_id' => $this->worker->id, 'role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    });

    afterEach(function (): void {
        Carbon::setTestNow();
    });

    it('reads a Node in a fleet rollout visit as updating in the list and on show', function (): void {
        $visit = nodeUpdatingVisit($this->worker, FleetRolloutStatus::Running, now()->subMinute());
        $expected = [
            'kind' => 'fleet_rollout',
            'since' => '2026-10-08T11:59:00+00:00',
            'rollout' => $visit->fleet_rollout_id,
            'release' => null,
        ];

        $list = $this->getJson('/api/v1/nodes')->assertOk();

        expect(collect($list->json('data'))->pluck('updating', 'name')->all())
            ->toBe(['gateway' => null, 'worker' => $expected]);

        $this->getJson("/api/v1/nodes/{$this->worker->id}")
            ->assertOk()
            ->assertJsonPath('data.updating', $expected);
    });

    it('counts a catch-up visit of a completed rollout', function (): void {
        nodeUpdatingVisit($this->worker, FleetRolloutStatus::Completed, now()->subMinute());

        $this->getJson("/api/v1/nodes/{$this->worker->id}")
            ->assertOk()
            ->assertJsonPath('data.updating.kind', 'fleet_rollout');
    });

    it('reads a long visit as updating while the Swamp Operation is active', function (): void {
        nodeUpdatingVisit($this->worker, FleetRolloutStatus::Running, now()->subHours(2));

        $this->getJson("/api/v1/nodes/{$this->worker->id}")
            ->assertOk()
            ->assertJsonPath('data.updating.kind', 'fleet_rollout');
    });

    it('reads a Node as not updating once the visit finished, before it started, or without a live run', function (string $case): void {
        match ($case) {
            'finished' => nodeUpdatingVisit($this->worker, FleetRolloutStatus::Running, now()->subMinutes(2), now()->subMinute()),
            'pending' => nodeUpdatingVisit($this->worker, FleetRolloutStatus::Running, null),
            'dead run' => nodeUpdatingVisit($this->worker, FleetRolloutStatus::Running, now()->subMinute()),
            'superseded' => nodeUpdatingVisit($this->worker, FleetRolloutStatus::Superseded, now()->subMinute()),
        };

        if ($case === 'dead run') {
            Operation::query()->where('kind', 'fleet.rollout')->update(['status' => 'failed']);
        }

        $this->getJson("/api/v1/nodes/{$this->worker->id}")
            ->assertOk()
            ->assertJsonPath('data.updating', null);
    })->with(['finished', 'pending', 'dead run', 'superseded']);

    it('reads the Gateway Node as updating while a release record runs', function (): void {
        nodeUpdatingRelease('verified');
        $running = nodeUpdatingRelease(GatewayRelease::Running);

        $list = $this->getJson('/api/v1/nodes')->assertOk();

        expect(collect($list->json('data'))->pluck('updating', 'name')->all())->toBe([
            'gateway' => [
                'kind' => 'gateway_release',
                'since' => '2026-10-08T12:00:00+00:00',
                'rollout' => null,
                'release' => $running->id,
            ],
            'worker' => null,
        ]);
    });

    it('does not read the Gateway Node as updating for a queued or finished release', function (string $outcome): void {
        nodeUpdatingRelease($outcome);

        $this->getJson("/api/v1/nodes/{$this->gateway->id}")
            ->assertOk()
            ->assertJsonPath('data.updating', null);
    })->with([GatewayRelease::Queued, 'verified', 'failed', GatewayRelease::Interrupted]);

    it('reads every Node of the list with one visit query and one release query', function (): void {
        foreach (range(1, 5) as $index) {
            $node = Node::query()->create([
                'name' => "extra-{$index}",
                'status' => LifecycleStatus::Active,
                'public_ssh_host' => "192.0.2.1{$index}",
                'wireguard_ip' => "10.44.0.1{$index}",
            ]);
            nodeUpdatingVisit($node, FleetRolloutStatus::Running, now()->subMinutes(30), now()->subMinutes(20));
        }
        nodeUpdatingVisit($this->worker, FleetRolloutStatus::Running, now()->subMinute());
        nodeUpdatingRelease(GatewayRelease::Running);

        DB::enableQueryLog();
        $list = $this->getJson('/api/v1/nodes')->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $count = static fn (string $table): int => count(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"'),
        ));

        expect($count('fleet_rollout_nodes'))->toBe(1)
            ->and($count('gateway_releases'))->toBe(1)
            ->and(collect($list->json('data'))->pluck('updating.kind', 'name')->filter()->all())
            ->toBe(['gateway' => 'gateway_release', 'worker' => 'fleet_rollout']);
    });
});
