<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskTurnPrompt;
use App\Models\Task;
use Carbon\CarbonImmutable;

/** The admission time of the turns in this file. */
function promptStart(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-16T10:00:00Z');
}

function promptSubtask(?array $deliverables): Task
{
    return new Task(['title' => 'Add the deny rule', 'brief' => "Add `firewall:deny`.\nKeep the shape of allow.", 'deliverables' => $deliverables]);
}

it('puts the title, brief and deliverables of the subtask in front of the prompt of the caller', function (): void {
    $subtask = promptSubtask([
        ['id' => 'chart', 'type' => 'file', 'description' => 'The chart exists.', 'path' => 'docs/cli/firewall/deny.mmd', 'change' => 'created'],
        ['id' => 'tests', 'type' => 'command', 'description' => 'The tests pass.', 'command' => 'vendor/bin/pest tests/Feature/DenyTest.php', 'directory' => 'apps/gateway'],
    ]);

    expect(TaskTurnPrompt::compose($subtask, 'Implement the brief.', promptStart()))->toBe(<<<'TEXT'
    Subtask: Add the deny rule

    Brief:
    Add `firewall:deny`.
    Keep the shape of allow.

    Deliverables:
    - chart (file: docs/cli/firewall/deny.mmd, created): The chart exists.
    - tests (command: `vendor/bin/pest tests/Feature/DenyTest.php` in apps/gateway): The tests pass.

    This turn started at 2026-11-16T10:00:00Z and Orbit ends it at 2026-11-16T11:00:00Z. Commit your work before then.

    Prompt of this turn:
    Implement the brief.
    TEXT);
});

it('leaves the deliverables section out when the subtask has none', function (): void {
    $text = TaskTurnPrompt::compose(promptSubtask(null), 'Review the checked commit.', promptStart());

    expect($text)->toStartWith("Subtask: Add the deny rule\n\nBrief:\n")
        ->and($text)->not->toContain('Deliverables:')
        ->and($text)->toContain("\n\nThis turn started at 2026-11-16T10:00:00Z and Orbit ends it at 2026-11-16T11:00:00Z. Commit your work before then.\n\n")
        ->and($text)->toEndWith("Prompt of this turn:\nReview the checked commit.");
});

it('refuses a composed prompt that the turn workflow would reject', function (): void {
    $subtask = promptSubtask(null);
    $room = TaskTurnPrompt::Limit - mb_strlen(TaskTurnPrompt::compose($subtask, '', promptStart()));

    expect(mb_strlen(TaskTurnPrompt::compose($subtask, str_repeat('a', $room), promptStart())))->toBe(TaskTurnPrompt::Limit)
        ->and(fn () => TaskTurnPrompt::compose($subtask, str_repeat('a', $room + 1), promptStart()))->toThrow(ResourceOperationException::class, 'longer than a turn accepts')
        // A character outside the basic plane counts as two code units, as the schema counts it.
        ->and(fn () => TaskTurnPrompt::compose($subtask, str_repeat('a', $room - 1).'😀', promptStart()))->toThrow(ResourceOperationException::class);
});
