<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnMode;
use App\Domain\Tasks\TaskTurnOutcome;
use App\Domain\Tasks\TaskTurnReceipt;

/**
 * The JSON receipt a prompt shows, read back from the prompt text.
 *
 * @return list<array<string, mixed>>
 */
function prompt_receipts(string $prompt): array
{
    preg_match_all('/the JSON receipt (\{.*?\}) written to \.git\/orbit\/receipt\.json/', $prompt, $matches);
    $receipts = [];
    foreach ($matches[1] as $json) {
        $receipt = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        expect($receipt)->toBeArray();
        $receipts[] = is_array($receipt) ? $receipt : [];
    }

    return $receipts;
}

it('tells every role to write the receipt file itself and never names the old turn command', function (string $prompt): void {
    expect($prompt)->toContain('written to .git/orbit/receipt.json')
        ->and($prompt)->not->toContain('orbit)/turn')
        ->and($prompt)->not->toContain('--outcome')
        ->and(prompt_receipts($prompt))->not->toBeEmpty();
    foreach (prompt_receipts($prompt) as $receipt) {
        expect($receipt['thread'])->toBe(14);
    }
})->with([
    'implementer' => fn (): string => TaskTurnInstructions::implementer([], null, 14),
    'reviewer' => fn (): string => TaskTurnInstructions::reviewer(threadId: 14),
    'final reviewer' => fn (): string => TaskTurnInstructions::reviewer(final: true, threadId: 14),
    'consult' => fn (): string => TaskTurnInstructions::consult(14),
    'relay' => fn (): string => TaskTurnInstructions::relay(14),
]);

it('shows receipts that the Gateway parses as the outcome they name', function (): void {
    $prompts = [
        TaskTurnInstructions::implementer([], null, 14),
        TaskTurnInstructions::reviewer(final: true, threadId: 14, mode: new TaskTurnMode(causeRequired: true)),
        TaskTurnInstructions::consult(14),
    ];
    $outcomes = [];
    foreach ($prompts as $prompt) {
        foreach (prompt_receipts($prompt) as $example) {
            $cause = isset($example['cause']) ? ['cause' => 'scope'] : [];
            $receipt = TaskTurnReceipt::parse(json_encode([...$example, ...$cause], JSON_THROW_ON_ERROR));
            expect($receipt->outcome?->value)->toBe($example['outcome'])
                ->and($receipt->threadId)->toBe(14);
            $outcomes[] = (string) $receipt->outcome?->value;
        }
    }

    expect(array_values(array_unique($outcomes)))->toEqualCanonicalizing(['ready_for_review', 'blocked', 'approved', 'changes_requested', 'answered']);
    $approval = prompt_receipts(TaskTurnInstructions::reviewer(final: true, threadId: 14))[0];
    expect(TaskTurnReceipt::parse(json_encode($approval, JSON_THROW_ON_ERROR))->pullRequest?->toArray())
        ->toBe(['summary' => 'One or two sentences about the whole feature', 'changes' => ['A new feature or behavior change'], 'breaking' => ['A breaking change']]);
});

it('names the deliverables to confirm in the receipt deliverables map', function (): void {
    $deliverables = [TaskDeliverable::fromArray(['id' => 'export-test', 'type' => 'review', 'description' => 'The export test passes.'])];

    expect(TaskTurnInstructions::implementer($deliverables, null, 14))->toContain('Add "deliverables": {"ID": "evidence"} with an entry for each deliverable of this subtask (export-test)')
        ->and(TaskTurnInstructions::reviewer(deliverables: $deliverables, threadId: 14))->toContain('confirm each review deliverable (export-test) in "deliverables": {"ID": "evidence"}');
});

it('reads an agent-written receipt with its thread, question, cause and confirmations', function (): void {
    $receipt = TaskTurnReceipt::parse(json_encode([
        'thread' => 14, 'outcome' => 'blocked', 'summary' => ' Stuck. ', 'question' => ' Which format? ', 'cause' => 'scope',
        'deliverables' => ['export-test' => ' tests/Feature/ExportTest.php ', 'empty' => ' '],
    ], JSON_THROW_ON_ERROR));

    expect($receipt->outcome)->toBe(TaskTurnOutcome::Blocked)
        ->and($receipt->threadId)->toBe(14)
        ->and($receipt->summary)->toBe('Stuck.')
        ->and($receipt->question)->toBe('Which format?')
        ->and($receipt->cause)->toBe('scope')
        ->and($receipt->deliverables)->toBe(['export-test' => 'tests/Feature/ExportTest.php'])
        ->and($receipt->body())->toBe("Stuck.\n\nQuestion: Which format?");
});

it('treats a hand-written receipt without an outcome and summary as invalid', function (string $contents): void {
    $receipt = TaskTurnReceipt::parse($contents);

    expect($receipt->outcome)->toBeNull()
        ->and($receipt->hash)->toBe(hash('sha256', $contents));
})->with([
    'invalid json' => ['{"outcome":'],
    'unknown outcome' => ['{"outcome":"done","summary":"Done."}'],
    'empty summary' => ['{"outcome":"blocked","summary":" "}'],
    'blocked without a question' => ['{"outcome":"blocked","summary":"Gateway implementation not completed."}'],
    'blocked with an empty question' => ['{"outcome":"blocked","summary":"Stuck.","question":" "}'],
    'an unknown cause' => ['{"outcome":"blocked","summary":"Stuck.","question":"Which?","cause":"taste"}'],
]);
