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

describe('own units of a checkout process stop', function (): void {
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
