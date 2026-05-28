<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\SchedulerState;
use App\Models\ScheduleRun;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use App\Services\Schedules\SchedulesProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function scheduleProbeIssue(array $drift, string $key): mixed
{
    return collect($drift)->first(fn ($entry): bool => $entry->key === $key);
}

function createSchedulesProbeGatewayNode(array $attributes = []): Node
{
    $node = Node::factory()->create([

        'status' => 'active',
        ...$attributes,
    ]);

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
        $node = createSchedulesProbeGatewayNode();
        $schedule = Schedule::factory()->forNode($node)->create();
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.target_invalid'))->toBeNull();
    });

    it('detects unavailable gateway scheduler runtime backend', function (): void {
        $gateway = createSchedulesProbeGatewayNode(['name' => 'gateway-1']);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeQueuedRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'orbit-runtime missing', durationMs: 1),
        ])));

        $drift = $probe->diffGateway($gateway, $probe->introspectGateway($gateway));

        expect(scheduleProbeIssue($drift, 'schedule.runtime_backend_unavailable')?->kind)->toBe(DriftKind::Missing)
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_missing'))->toBeNull();
    });

    it('detects missing scheduler configuration in the gateway runtime container', function (): void {
        $gateway = createSchedulesProbeGatewayNode();
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeQueuedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "running=true\nrestart_policy=unless-stopped\nscheduler_running=false\n", stderr: '', durationMs: 1),
        ])));

        $drift = $probe->diffGateway($gateway, $probe->introspectGateway($gateway));

        expect(scheduleProbeIssue($drift, 'schedule.scheduler_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects stopped scheduler runtime container on the gateway', function (): void {
        $gateway = createSchedulesProbeGatewayNode();
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeQueuedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "running=false\nrestart_policy=unless-stopped\nenv=ORBIT_IS_GATEWAY=1\nscheduler_running=false\n", stderr: '', durationMs: 1),
        ])));

        $drift = $probe->diffGateway($gateway, $probe->introspectGateway($gateway));

        expect(scheduleProbeIssue($drift, 'schedule.scheduler_stopped')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects an absent scheduler process inside a live gateway runtime container', function (): void {
        $gateway = createSchedulesProbeGatewayNode();
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeQueuedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "running=true\nrestart_policy=unless-stopped\nenv=ORBIT_IS_GATEWAY=1\nscheduler_running=false\n", stderr: '', durationMs: 1),
        ])));

        $drift = $probe->diffGateway($gateway, $probe->introspectGateway($gateway));

        expect(scheduleProbeIssue($drift, 'schedule.scheduler_stopped')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects stale gateway heartbeat', function (): void {
        $gateway = createSchedulesProbeGatewayNode();
        SchedulerState::factory()->create([
            'node_id' => $gateway->id,
            'heartbeat_at' => now()->subMinutes(20),
            'registry_synced_at' => now()->subMinutes(20),
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeQueuedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "running=true\nrestart_policy=unless-stopped\nenv=ORBIT_IS_GATEWAY=1\nscheduler_running=true\n", stderr: '', durationMs: 1),
        ])));

        $drift = $probe->diffGateway($gateway, $probe->introspectGateway($gateway));

        expect(scheduleProbeIssue($drift, 'schedule.heartbeat_stale')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects stuck gateway schedule locks', function (): void {
        $gateway = createSchedulesProbeGatewayNode();
        SchedulerState::factory()->create([
            'node_id' => $gateway->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);
        ScheduleLock::factory()->create([
            'node_id' => $gateway->id,
            'schedule_key' => 'app:docs:laravel-scheduler',
            'locked_at' => now()->subMinutes(30),
            'expires_at' => now()->subMinutes(20),
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeQueuedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "running=true\nrestart_policy=unless-stopped\nenv=ORBIT_IS_GATEWAY=1\nscheduler_running=true\n", stderr: '', durationMs: 1),
        ])));

        $drift = $probe->diffGateway($gateway, $probe->introspectGateway($gateway));

        expect(scheduleProbeIssue($drift, 'schedule.lock_stuck')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects unreachable schedule targets from the gateway', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell(exitCode: 255, stderr: 'ssh timeout')));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.target_unreachable')?->kind)->toBe(DriftKind::Missing)
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_missing'))->toBeNull();
    });

    it('detects stuck schedule run history', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        ScheduleRun::factory()->create([
            'node_id' => $node->id,
            'schedule_key' => $schedule->schedule_key,
            'status' => 'running',
            'started_at' => now()->subMinutes(30),
            'finished_at' => null,
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.run_stuck')?->kind)->toBe(DriftKind::Divergent);
    });
});

final readonly class SchedulesProbeRemoteShell implements RemoteShell
{
    public function __construct(
        private int $exitCode = 0,
        private string $stdout = "running\n",
        private string $stderr = '',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $this->stdout, stderr: $this->stderr, durationMs: 1);
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
