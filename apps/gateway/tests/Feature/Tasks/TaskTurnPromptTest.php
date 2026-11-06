<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskTurnPrompt;
use App\Models\Task;

function promptSubtask(?array $deliverables): Task
{
    return new Task(['title' => 'Add the deny rule', 'brief' => "Add `firewall:deny`.\nKeep the shape of allow.", 'deliverables' => $deliverables]);
}

it('puts the title, brief and deliverables of the subtask in front of the prompt of the caller', function (): void {
    $subtask = promptSubtask([
        ['id' => 'chart', 'type' => 'file', 'description' => 'The chart exists.', 'path' => 'docs/cli/firewall/deny.mmd', 'change' => 'created'],
        ['id' => 'plan', 'type' => 'command', 'description' => 'The plan passes.', 'command' => 'python3 swamp/proof/runner/runner.py plan --file x.cases.yaml', 'directory' => '.'],
    ]);

    expect(TaskTurnPrompt::compose($subtask, 'Implement the brief.'))->toBe(<<<'TEXT'
    Subtask: Add the deny rule

    Brief:
    Add `firewall:deny`.
    Keep the shape of allow.

    Deliverables:
    - chart (file: docs/cli/firewall/deny.mmd, created): The chart exists.
    - plan (command: `python3 swamp/proof/runner/runner.py plan --file x.cases.yaml` in .): The plan passes.

    Prompt of this turn:
    Implement the brief.
    TEXT);
});

it('leaves the deliverables section out when the subtask has none', function (): void {
    $text = TaskTurnPrompt::compose(promptSubtask(null), 'Review the checked commit.');

    expect($text)->toStartWith("Subtask: Add the deny rule\n\nBrief:\n")
        ->and($text)->not->toContain('Deliverables:')
        ->and($text)->toEndWith("Prompt of this turn:\nReview the checked commit.");
});
