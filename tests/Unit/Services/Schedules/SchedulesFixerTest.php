<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Schedules\SchedulesFixer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('SchedulesFixer', function (): void {
    it('installs the orbit scheduler supervisor program when missing', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        $shell = new SchedulesFixerRemoteShell;

        $action = (new SchedulesFixer($shell))->fix($schedule, new DriftEntry(
            family: 'schedule',
            key: 'schedule.scheduler_missing',
            kind: DriftKind::Missing,
            summary: 'Orbit Scheduler program is missing.',
        ));

        expect($action)->toMatchArray([
            'family' => 'schedule',
            'node' => 'app-1',
            'key' => 'schedule.scheduler_missing',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and($shell->scripts[0])->toContain('[program:orbit_scheduler]')
            ->and($shell->scripts[0])->toContain("sudo supervisorctl update 'orbit_scheduler'");
    });

    it('starts the orbit scheduler supervisor program when stopped', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        $shell = new SchedulesFixerRemoteShell;

        $action = (new SchedulesFixer($shell))->fix($schedule, new DriftEntry(
            family: 'schedule',
            key: 'schedule.scheduler_stopped',
            kind: DriftKind::Divergent,
            summary: 'Orbit Scheduler program is not running.',
        ));

        expect($action)->toMatchArray([
            'family' => 'schedule',
            'node' => 'app-1',
            'key' => 'schedule.scheduler_stopped',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and($shell->scripts)->toBe(["sudo supervisorctl start 'orbit_scheduler'"]);
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
