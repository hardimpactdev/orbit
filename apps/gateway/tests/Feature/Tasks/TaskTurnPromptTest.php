<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCopyTurn;
use App\Domain\Tasks\TaskTurnPrompt;
use App\Models\Task;
use Carbon\CarbonImmutable;

/** The admission time of the turns in this file. */
function promptStart(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-16T10:00:00Z');
}

function promptSubtask(?array $deliverables, string $groupBrief = "Add a firewall rule set.\nAllow and deny share one shape."): Task
{
    $subtask = new Task(['title' => 'Add the deny rule', 'brief' => "Add `firewall:deny`.\nKeep the shape of allow.", 'deliverables' => $deliverables]);

    return $subtask->setRelation('parent', new Task(['title' => 'Firewall rules', 'brief' => $groupBrief]));
}

it('puts the group, then the subtask, then the copy rules in front of the prompt of the caller', function (): void {
    $subtask = promptSubtask([
        ['id' => 'chart', 'type' => 'file', 'description' => 'The chart exists.', 'path' => 'docs/cli/firewall/deny.mmd', 'change' => 'created'],
        ['id' => 'tests', 'type' => 'command', 'description' => 'The tests pass.', 'command' => 'vendor/bin/pest tests/Feature/DenyTest.php', 'directory' => 'apps/gateway'],
    ]);

    expect(TaskTurnPrompt::compose($subtask, 'Implement the brief.', promptStart()))->toBe(<<<'TEXT'
    Task group: Firewall rules

    Group brief:
    Add a firewall rule set.
    Allow and deny share one shape.

    Subtask: Add the deny rule

    Brief:
    Add `firewall:deny`.
    Keep the shape of allow.

    Deliverables:
    - chart (file: docs/cli/firewall/deny.mmd, created): The chart exists.
    - tests (command: `vendor/bin/pest tests/Feature/DenyTest.php` in apps/gateway): The tests pass.

    Copy rules:
    - Build and prove the feature end to end in this copy, with its nested fleet when it has one, before its pull request: change, run, fix the next layer and repeat until the real end result works here. A release or a live window only confirms a working feature; it never finds its bugs.
    - Run every command in the foreground. Leave none running when this turn ends.
    - The Project's long gates and full test sets are the work of the task check and CI (`task:check`). Run single test files by path.
    - Keep each new or changed test unit within the per-unit time cap of the Project's CI, which the task check prints.
    - Commit your work before this turn ends.
    - Never print an environment file, a token, a key or a session value.

    This turn started at 2026-11-16T10:00:00Z and Orbit ends it at 2026-11-16T11:00:00Z. Commit your work before then.

    Prompt of this turn:
    Implement the brief.
    TEXT);
});

it('leaves the deliverables section out when the subtask has none', function (): void {
    $text = TaskTurnPrompt::compose(promptSubtask(null), 'Review the checked commit.', promptStart());

    expect($text)->toStartWith("Task group: Firewall rules\n\nGroup brief:\nAdd a firewall rule set.\nAllow and deny share one shape.\n\nSubtask: Add the deny rule\n\nBrief:\n")
        ->and($text)->not->toContain('Deliverables:')
        ->and(substr_count($text, TaskTurnPrompt::CopyRules))->toBe(1)
        ->and($text)->toEndWith(TaskTurnPrompt::CopyRules."\n\nThis turn started at 2026-11-16T10:00:00Z and Orbit ends it at 2026-11-16T11:00:00Z. "
            ."Commit your work before then.\n\nPrompt of this turn:\nReview the checked commit.");
});

it('names no command of one Project in the copy rules', function (): void {
    expect(TaskTurnPrompt::CopyRules)->not->toContain('bin/')->not->toContain('composer')->not->toContain('pest')->not->toContain('swamp');
});

it('tells the reviewer that its text opens the pull request, and updates one that is open already on the next publish', function (): void {
    expect(TaskCopyTurn::PrTextRule)->toContain('Orbit opens the pull request with it once the work is done; when the pull request is open already, '
        .'the next publish of this check updates its title and body with it.');
});

it('refuses a composed prompt that the turn workflow would reject', function (): void {
    $subtask = promptSubtask(null);
    $room = TaskTurnPrompt::Limit - mb_strlen(TaskTurnPrompt::compose($subtask, '', promptStart()));

    expect(mb_strlen(TaskTurnPrompt::compose($subtask, str_repeat('a', $room), promptStart())))->toBe(TaskTurnPrompt::Limit)
        ->and(fn () => TaskTurnPrompt::compose($subtask, str_repeat('a', $room + 1), promptStart()))->toThrow(ResourceOperationException::class, 'longer than a turn accepts')
        // A character outside the basic plane counts as two code units, as the schema counts it.
        ->and(fn () => TaskTurnPrompt::compose($subtask, str_repeat('a', $room - 1).'😀', promptStart()))->toThrow(ResourceOperationException::class);
});

it('counts the group brief in the limit', function (): void {
    $short = promptSubtask(null, 'Brief');
    $long = promptSubtask(null, str_repeat('b', 8000));
    $prompt = str_repeat('a', TaskTurnPrompt::Limit - mb_strlen(TaskTurnPrompt::compose($short, '', promptStart())));

    expect(mb_strlen(TaskTurnPrompt::compose($short, $prompt, promptStart())))->toBe(TaskTurnPrompt::Limit)
        ->and(fn () => TaskTurnPrompt::compose($long, $prompt, promptStart()))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tasks.prompt_too_large'));
});
