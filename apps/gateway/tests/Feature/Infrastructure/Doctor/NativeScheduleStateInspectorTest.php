<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleSpecification;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Doctor\NativeScheduleStateInspector;
use App\Models\Node;
use App\Models\Schedule;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->agents = new FakeAgentCommandRunner;
    $this->inspector = new NativeScheduleStateInspector(new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver), new ScheduleSpecification, $this->agents);
    $this->node = Node::query()->create([
        'name' => 'schedule-inspector', 'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'public_ssh_host' => '192.0.2.91', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.91',
    ]);
    $this->schedule = Schedule::query()->create([
        'target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id,
        'name' => 'doctor', 'calendar' => 'daily', 'command' => 'printf "private schedule output"',
        'timeout_seconds' => 3600, 'desired_timer_state' => DesiredTimerState::Disabled, 'status' => LifecycleStatus::Active,
    ]);
});

it('maps native observations and sends the derived execution context in one call', function (): void {
    $this->agents->answer('doctor.inspect-schedule', [
        'artifacts_present' => true, 'permissions_match' => false, 'specification_match' => true,
        'timer_state_match' => false, 'calendar_match' => true, 'execution_context_match' => false, 'completion_callback_match' => true,
    ]);

    $result = $this->inspector->inspect($this->schedule);

    expect((array) $result)->toBe([
        'artifactsPresent' => true, 'permissionsMatch' => false, 'specificationMatch' => true,
        'timerStateMatch' => false, 'calendarMatch' => true, 'executionContextMatch' => false, 'completionCallbackMatch' => true,
    ]);
    expect($this->agents->commands())->toBe(['doctor.inspect-schedule'])
        ->and($this->agents->calls[0]['input'])->toBe([
            'id' => $this->schedule->id, 'user' => 'orbit', 'group' => 'orbit', 'home' => '/home/orbit',
            'working_directory' => '/home/orbit', 'shell' => '/bin/bash', 'login_shell' => true,
            'command' => $this->schedule->command, 'calendar' => 'daily', 'timeout_seconds' => 3600, 'desired' => 'disabled',
        ]);
});

it('reports failed or incomplete native observations as unavailable', function (bool $refused): void {
    if ($refused) {
        $this->agents->refuse('doctor.inspect-schedule', 'schedule.failed');
    } else {
        $this->agents->answerMalformed('doctor.inspect-schedule', ['artifacts_present' => true]);
    }

    expect(fn () => $this->inspector->inspect($this->schedule))->toThrow(DoctorInspectionException::class);
})->with([true, false]);

it('passes known intent to the native orphan scan', function (): void {
    $orphan = '123e4567-e89b-42d3-a456-426614174000';
    $this->agents->answer('doctor.schedule-orphans', ['ids' => [$orphan]]);

    expect($this->inspector->orphanIds($this->node, [$this->schedule->id]))->toBe([$orphan])
        ->and($this->agents->calls[0]['input'])->toBe(['known_ids' => [$this->schedule->id]]);
});

it('does not report a malformed orphan scan as empty', function (): void {
    $this->agents->answerMalformed('doctor.schedule-orphans', ['ids' => [false]]);

    expect(fn () => $this->inspector->orphanIds($this->node, []))->toThrow(DoctorInspectionException::class);
});

it('keeps Schedule management and observation behind the agent boundary', function (): void {
    foreach (['Infrastructure/Doctor/NativeScheduleStateInspector.php', 'Infrastructure/Schedules/RemoteScheduleRuntimeManager.php', 'Domain/Schedules/ScheduleSpecification.php'] as $path) {
        foreach (['SshExecutor', 'RemoteCommand', 'ProcessRunner', '<<<', 'renderScript', 'renderService'] as $forbidden) {
            expect(file_get_contents(app_path($path)))->not->toContain($forbidden);
        }
    }
});
