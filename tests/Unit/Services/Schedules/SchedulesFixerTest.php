<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\ScheduleRun;
use App\Services\Schedules\SchedulesFixer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function createSchedulesFixerGatewayNode(): Node
{
    $node = Node::factory()->create(['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active']);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $node;
}

describe('SchedulesFixer', function (): void {
    it('installs the orbit scheduler supervisor program on the gateway when missing', function (): void {
        $gateway = createSchedulesFixerGatewayNode();
        $shell = new SchedulesFixerRemoteShell;

        $action = (new SchedulesFixer($shell))->fixGateway($gateway, new DriftEntry(
            family: 'schedule',
            key: 'schedule.scheduler_missing',
            kind: DriftKind::Missing,
            summary: 'Orbit Scheduler program is missing.',
        ));

        expect($action)->toMatchArray([
            'family' => 'schedule',
            'node' => 'gateway-1',
            'key' => 'schedule.scheduler_missing',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and(base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true))->toContain('[program:orbit_scheduler]')
            ->and($shell->scripts[0])->toContain("sudo supervisorctl update 'orbit_scheduler'");
    });

    it('starts the orbit scheduler supervisor program on the gateway when stopped', function (): void {
        $gateway = createSchedulesFixerGatewayNode();
        $shell = new SchedulesFixerRemoteShell;

        $action = (new SchedulesFixer($shell))->fixGateway($gateway, new DriftEntry(
            family: 'schedule',
            key: 'schedule.scheduler_stopped',
            kind: DriftKind::Divergent,
            summary: 'Orbit Scheduler program is not running.',
        ));

        expect($action)->toMatchArray([
            'family' => 'schedule',
            'node' => 'gateway-1',
            'key' => 'schedule.scheduler_stopped',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and($shell->scripts)->toBe(["sudo supervisorctl start 'orbit_scheduler'"]);
    });

    it('releases stale gateway schedule locks and marks running history failed', function (): void {
        $gateway = createSchedulesFixerGatewayNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        ScheduleLock::factory()->create([
            'node_id' => $gateway->id,
            'schedule_key' => $schedule->schedule_key,
            'locked_at' => now()->subMinutes(30),
            'expires_at' => now()->subMinutes(20),
        ]);
        ScheduleRun::factory()->create([
            'node_id' => $node->id,
            'schedule_key' => $schedule->schedule_key,
            'status' => 'running',
            'exit_code' => null,
            'finished_at' => null,
        ]);
        $shell = new SchedulesFixerRemoteShell;

        $action = (new SchedulesFixer($shell))->fixGateway($gateway, new DriftEntry(
            family: 'schedule',
            key: 'schedule.lock_stuck',
            kind: DriftKind::Divergent,
            summary: 'Schedule has a stale execution lock.',
            detail: ['schedule_key' => $schedule->schedule_key],
        ), $schedule);

        $run = ScheduleRun::query()->where('schedule_key', $schedule->schedule_key)->firstOrFail();

        expect($action)->toMatchArray([
            'family' => 'schedule',
            'node' => 'gateway-1',
            'key' => 'schedule.lock_stuck',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and(ScheduleLock::query()->where('schedule_key', $schedule->schedule_key)->exists())->toBeFalse()
            ->and($run->status)->toBe('failed')
            ->and($run->stderr)->toContain('Schedule lock was released by doctor restore.')
            ->and($shell->scripts)->toBe([]);
    });
});

final class SchedulesFixerRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
