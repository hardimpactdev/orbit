<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskTurnPullRequest;

it('accepts only a complete pull request description from a receipt', function (mixed $data, bool $valid): void {
    expect(TaskTurnPullRequest::fromArray($data) instanceof TaskTurnPullRequest)->toBe($valid);
})->with([
    'complete' => [['summary' => 'S', 'changes' => ['C'], 'breaking' => []], true],
    'no changes' => [['summary' => 'S', 'changes' => [], 'breaking' => []], false],
    'empty summary' => [['summary' => ' ', 'changes' => ['C'], 'breaking' => []], false],
    'missing breaking' => [['summary' => 'S', 'changes' => ['C']], false],
    'empty change' => [['summary' => 'S', 'changes' => [''], 'breaking' => []], false],
    'not an array' => [null, false],
]);
