<?php

declare(strict_types=1);

use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceRemoval;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Schedule;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Runs the removal's `stop_own_tree_processes` on `$tree` with every process's cgroup read as `$cgroup`, as if the
 * process ran in that systemd unit, and returns whether the process `$pid` still runs afterwards.
 */
function own_units_stop(string $tree, int $pid, string $cgroup, array $ownUnits): bool
{
    $script = RemoteDevelopmentInstanceSourceRemoval::releaseEmptyGroupingDirectoryFunction(true, $ownUnits).<<<'BASH'
        cat() {
            case "${@: -1}" in
                */cgroup) printf '%s\n' "$CGROUP" ;;
                *) command cat "$@" ;;
            esac
        }
        stop_own_tree_processes "$1"
        BASH;
    (new SymfonyProcess(['bash', '-c', $script, '_', $tree], null, ['CGROUP' => $cgroup]))->mustRun();
    usleep(100_000);
    $stat = @file_get_contents("/proc/{$pid}/stat");

    return is_string($stat) && preg_match('/\) Z /', $stat) !== 1;
}

function own_units_spawn(string $directory): int
{
    $process = new SymfonyProcess(['bash', '-c', 'cd -- "$1" && { nohup sleep 300 >/dev/null 2>&1 </dev/null & printf %s "$!"; }', '_', $directory]);
    $process->mustRun();

    return (int) $process->getOutput();
}

describe('own units of a checkout process stop', function (): void {
    beforeEach(function (): void {
        $this->tree = sys_get_temp_dir().'/orbit-own-units-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tree);
    });
    afterEach(function (): void {
        File::deleteDirectory($this->tree);
    });

    it('stops a process inside the tree that runs in another Orbit Process unit, such as the agents\' pi-server', function (): void {
        $pid = own_units_spawn($this->tree);
        try {
            expect(own_units_stop($this->tree, $pid, '0::/system.slice/orbit-process-148-pi-server.service', ['orbit-process-7-web.service']))->toBeFalse();
        } finally {
            posix_kill($pid, SIGKILL);
        }
    });

    it('leaves a process of one of the Instance\'s own units running', function (string $unit): void {
        $pid = own_units_spawn($this->tree);
        try {
            expect(own_units_stop($this->tree, $pid, '0::/system.slice/'.$unit, ['orbit-process-7-web.service', 'orbit-schedule-0f8b1c3e-2a4d-4e6f-9a1b-3c5d7e9f1a2b.service']))->toBeTrue();
        } finally {
            posix_kill($pid, SIGKILL);
        }
    })->with(['a Process' => 'orbit-process-7-web.service', 'a Schedule' => 'orbit-schedule-0f8b1c3e-2a4d-4e6f-9a1b-3c5d7e9f1a2b.service']);

    it('lists exactly the systemd units of the Instance\'s own Processes and Schedules', function (): void {
        $project = Project::query()->create(['name' => 'Units', 'slug' => 'units', 'repository_url' => 'https://github.com/acme/units.git']);
        $node = Node::query()->create(['name' => 'units', 'wireguard_ip' => '192.0.2.40', 'public_ssh_host' => '192.0.2.40']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-9', 'checkout_path' => '/srv/apps/units/task-9', 'status' => 'active']);
        $other = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-10', 'checkout_path' => '/srv/apps/units/task-10', 'status' => 'active']);
        $process = fn (Instance|Node $owner, string $name, string $runtime): Process => Process::query()->create([
            'owner_type' => $owner instanceof Instance ? Instance::MorphAlias : $owner->getMorphClass(), 'owner_id' => $owner->id, 'name' => $name,
            'runtime' => $runtime, 'runtime_config' => ['command' => ['sleep', '1']] + ($runtime === 'docker' ? ['image' => 'alpine'] : []),
            'working_directory' => '/srv/apps/units', 'restart_policy' => 'on-failure', 'desired_state' => 'running', 'status' => 'active',
        ]);
        $web = $process($instance, 'web', 'systemd');
        $process($instance, 'redis', 'docker');
        $process($other, 'web', 'systemd');
        $process($node, 'pi-server', 'systemd');
        $schedule = Schedule::query()->create([
            'target_type' => $instance->getMorphClass(), 'target_id' => $instance->id, 'host_node_id' => $node->id, 'name' => 'nightly',
            'calendar' => 'daily', 'command' => 'true', 'timeout_seconds' => 60, 'desired_timer_state' => DesiredTimerState::Enabled, 'status' => LifecycleStatus::Active,
        ]);

        $expected = ["orbit-process-{$web->id}-web.service", 'orbit-schedule-'.$schedule->id.'.service'];
        sort($expected);
        expect(RemoteDevelopmentInstanceSourceRemoval::ownUnits($instance))->toBe($expected);
    });
});
