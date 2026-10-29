<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Schedules\ScheduleSpecification;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Schedules\RemoteScheduleRuntimeManager;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Schedule;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

it('keeps Process status and bounded logs on the Orbit read path', function (): void {
    $node = Node::query()->create(['name' => 'dev', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'proof', 'runtime' => 'docker', 'runtime_config' => ['image' => 'example:1', 'command' => ['sleep', '300']], 'restart_policy' => 'never', 'desired_state' => 'stopped', 'status' => 'active']);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('process.status', ['state' => 'inactive']);
    $agents->answer('process.logs', ['output' => "older\nnewer\n", 'truncated' => false]);
    $reader = new RemoteProcessRuntimeManager(app(ProcessTargetResolver::class), app(ManagedUserAccountResolver::class), $agents, new SystemdProcessRenderer, new DockerProcessRenderer);
    expect($reader->status($process))->toBe('inactive')->and($reader->logs($process, 25))->toBe("older\nnewer\n");
    expect($agents->commands())->toBe(['process.status', 'process.logs']);
});

it('keeps Schedule journal reads bounded and out of Operation state', function (): void {
    $node = Node::query()->create(['name' => 'dev', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3']);
    $schedule = Schedule::query()->create(['target_type' => Node::class, 'target_id' => $node->id, 'host_node_id' => $node->id, 'name' => 'proof', 'calendar' => 'daily', 'command' => 'true', 'timeout_seconds' => 3600, 'desired_timer_state' => 'enabled', 'status' => 'active']);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('schedule.logs', ['output' => "older\nnewer\n", 'truncated' => false]);
    $reader = new RemoteScheduleRuntimeManager(new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver), app(ScheduleSpecification::class), $agents);
    $result = $reader->logs($schedule, 25);
    expect($result->output)->toBe("older\nnewer\n")->and($agents->calls[0]['input'])->toBe(['id' => $schedule->id, 'lines' => 25, 'max_bytes' => 1048576]);
    expect(Operation::query()->count())->toBe(0);
});
