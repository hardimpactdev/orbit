<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSubtaskRunner;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\AgentSnapshotReader;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;

beforeEach(function (): void {
    tick_bind_defaults();
});

it('withholds output rather than storing secrets after a real PCRE redaction failure', function (): void {
    Log::spy();
    $group = tick_group();
    $task = $group->tasks()->sole();
    $check = TaskCheck::query()->create([
        'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running,
        'pid' => 1, 'process_started' => 'start', 'head_before' => str_repeat('a', 40),
        'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
    $output = str_repeat('a-', 50_000).": x\npassword: do-not-store-password\nTOKEN=do-not-store-token\nFAILED last line";

    app(TaskSubtaskRunner::class)->recordReading($check, TaskCheckReading::finished(
        1, str_repeat('a', 40), str_repeat('b', 40), [], $output,
    ));

    expect($check->fresh()->output)->toBe('Output withheld: redaction failed.');
    Log::shouldHaveReceived('warning')->once()->with('Output withheld: redaction failed.', [
        'pcre_error' => PREG_JIT_STACKLIMIT_ERROR,
    ]);
});

it('bounds and redacts agent-facing check output through the same policy', function (string $source): void {
    Exceptions::fake()->throwOnReport();
    $output = "Early line\n".str_repeat("padding\n", 6000)
        ."2026-10-18T10:11:12.123Z \e[31mFAILED ExportTest\e[0m\nTOKEN=".str_repeat('s', 40000)."\n--token=\"v\"name\"\n```\nLast line";
    $dispatcher = tick_dispatcher();
    if ($source === 'CI') {
        tick_settling_group();
        tick_running_agents();
        tick_watch_pulls([tick_open_pull()], ['abc123' => [[
            'id' => 9, 'name' => 'Export', 'status' => 'completed', 'conclusion' => 'failure', 'app' => ['slug' => 'github-actions'],
        ]]]);
        Http::fake([
            'https://api.github.com/repos/acme/orbit/actions/jobs/9/logs' => Http::response('', 302, ['Location' => 'https://logs.blob.core.windows.net/job']),
            'https://logs.blob.core.windows.net/job' => Http::response($output),
        ]);
    } else {
        tick_group();
        app(TaskExtensionState::class)->enable();
        app()->instance(AgentCommandDispatcher::class, $dispatcher);
        app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
        {
            public function snapshot(Node $node, string $threadId): ?array
            {
                return tick_checked_thread('idle', $threadId);
            }
        });
        app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([
            TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], $output),
        ]));
        app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
            FakeTaskTurnReceipts::contents('ready_for_review'), null,
        ]));
    }

    app(TaskScheduler::class)->tick();
    if ($source === 'Orbit') {
        app(TaskScheduler::class)->tick();
    }

    $message = $source === 'CI'
        ? Task::query()->where('fixup_problem', 'check:Export')->sole()->brief
        : $dispatcher->commands[0]['message']['text'];
    expect($message)->toContain('FAILED ExportTest', 'TOKEN=[REDACTED]', '--token=[REDACTED]name"', "\n````\n")
        ->not->toContain('ssss')
        ->not->toContain('Early line')
        ->not->toContain('2026-10-18T')
        ->not->toContain("\e[");
    preg_match('/\n(`{4,})\n(.*?)\n\1/s', $message, $excerpt);
    expect($excerpt[2])->toEndWith("```\nLast line")
        ->and(strlen($excerpt[2]))->toBeLessThanOrEqual(16 * 1024);
})->with(['Orbit', 'CI']);

it('stores only redacted 16 KiB tails for check and command evidence', function (bool $lost): void {
    $group = tick_group();
    $task = $group->tasks()->sole();
    $check = TaskCheck::query()->create([
        'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running,
        'pid' => 1, 'process_started' => 'start', 'head_before' => str_repeat('a', 40),
        'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
    $output = "Early line\n".str_repeat("padding\n", 15_000).'TOKEN='.str_repeat('s', 40_000)."\nFAILED last line";
    $reading = $lost ? TaskCheckReading::lost($output) : TaskCheckReading::finished(
        1, str_repeat('a', 40), str_repeat('b', 40), [], $output,
        deliverables: ['diff' => [], 'topology_diff' => [], 'commands' => [
            'repro' => ['exit_code' => 1, 'output' => $output, 'base_started' => true, 'base_exit_code' => 2, 'base_output' => $output],
        ]],
    );

    app(TaskSubtaskRunner::class)->recordReading($check, $reading);

    expect($check->output)->toContain('TOKEN=[REDACTED]', 'FAILED last line')
        ->not->toContain('ssss')->not->toContain('Early line')
        ->and(strlen($check->output))->toBeLessThanOrEqual(16 * 1024);
    if (! $lost) {
        $run = $check->deliverable_evidence['commands']['repro'];
        foreach (['output', 'base_output'] as $field) {
            expect($run[$field])->toBe($check->output);
        }
        expect($run['exit_code'])->toBe(1)->and($run['base_exit_code'])->toBe(2);
    }
})->with([false, true]);
