<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\SchedulerState;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use App\Services\Schedules\ScheduleRunHistoryHookRenderer;
use App\Services\Schedules\SchedulesProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function scheduleProbeIssue(array $drift, string $key): mixed
{
    return collect($drift)->first(fn ($entry): bool => $entry->key === $key);
}

function createSchedulesProbeGatewayAssignmentNode(): Node
{
    $node = Node::factory()->create(['role' => 'control', 'status' => 'active']);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    return $node;
}

describe('SchedulesProbe', function (): void {
    it('has key and label', function (): void {
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell));

        expect($probe->key())->toBe('schedule')
            ->and($probe->label())->toBe('Schedules');
    });

    it('detects incomplete schedule records', function (): void {
        $schedule = Schedule::factory()->create([
            'scope' => 'app',
            'app_id' => null,
            'target_name' => '',
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects invalid app targets', function (): void {
        $schedule = Schedule::factory()->create([
            'scope' => 'app',
            'app_id' => null,
            'target_name' => 'missing-app',
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.target_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('accepts active gateway role assignments as schedule targets', function (): void {
        $node = createSchedulesProbeGatewayAssignmentNode();
        $schedule = Schedule::factory()->forNode($node)->create();
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell(exitCode: 1)));

        $drift = $probe->diff($schedule, new ProbeSnapshot([
            $schedule->schedule_key => ['runtime_available' => false],
        ]));

        expect(scheduleProbeIssue($drift, 'schedule.target_invalid'))->toBeNull()
            ->and(scheduleProbeIssue($drift, 'schedule.runtime_backend_unavailable')?->kind)->toBe(DriftKind::Missing);
    });

    it('short-circuits scheduler checks when runtime backend is unavailable', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell(exitCode: 1)));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.runtime_backend_unavailable')?->kind)->toBe(DriftKind::Missing)
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_missing'))->toBeNull()
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_stopped'))->toBeNull();
    });

    it('detects missing scheduler supervisor program', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell(stdout: "missing\n")));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.scheduler_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects stopped scheduler supervisor program', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell(stdout: "stopped\n")));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.scheduler_stopped')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects stale heartbeat and registry sync state', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        SchedulerState::factory()->create([
            'node_id' => $node->id,
            'heartbeat_at' => now()->subMinutes(20),
            'registry_synced_at' => now()->subMinutes(20),
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell(stdout: "running\n")));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.heartbeat_stale')?->kind)->toBe(DriftKind::Divergent)
            ->and(scheduleProbeIssue($drift, 'schedule.registry_sync_stale')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects stuck schedule locks', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        SchedulerState::factory()->create([
            'node_id' => $node->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);
        ScheduleLock::factory()->create([
            'node_id' => $node->id,
            'schedule_key' => $schedule->schedule_key,
            'locked_at' => now()->subMinutes(30),
            'expires_at' => now()->subMinutes(20),
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell(stdout: "running\n")));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.lock_stuck')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects missing run history hook material', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        SchedulerState::factory()->create([
            'node_id' => $node->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeQueuedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "running\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "0\t\n", stderr: '', durationMs: 1),
        ])));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.run_history_hook_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects divergent run history hook material', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        SchedulerState::factory()->create([
            'node_id' => $node->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeQueuedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "running\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "1\tdeadbeef\n", stderr: '', durationMs: 1),
        ])));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.run_history_hook_mismatch')?->kind)->toBe(DriftKind::Divergent);
    });

    it('accepts matching run history hook material', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        SchedulerState::factory()->create([
            'node_id' => $node->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);
        $renderer = new ScheduleRunHistoryHookRenderer;
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeQueuedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "running\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "1\t{$renderer->hash($schedule)}\n", stderr: '', durationMs: 1),
        ])), $renderer);

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.run_history_hook_missing'))->toBeNull()
            ->and(scheduleProbeIssue($drift, 'schedule.run_history_hook_mismatch'))->toBeNull();
    });
});

final readonly class SchedulesProbeRemoteShell implements RemoteShell
{
    public function __construct(
        private int $exitCode = 0,
        private string $stdout = "running\n",
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}

final class SchedulesProbeQueuedRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
