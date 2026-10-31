<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmFamilyChildren;
use App\Models\Operation;

it('reads stored Node enrollment intents without dispatch or hydration', function (): void {
    $intent = ['key' => 'node-enrollment', 'kind' => 'node.add', 'node_id' => 17,
        'input' => ['name' => 'tvm-5', 'credential' => ['$secret' => 'node.worker_credentials', 'id' => 17]]];
    $operation = new Operation(['state' => ['child_intents' => [$intent]]]);

    expect(app(TaskVmFamilyChildren::class)->nodeIntents($operation))->toBe([$intent]);
    expect(Operation::query()->count())->toBe(0);
});

it('returns no stored Node intents when none were recorded', function (): void {
    expect(app(TaskVmFamilyChildren::class)->nodeIntents(new Operation(['state' => []])))->toBe([]);
});

it('rejects malformed stored Node intent lists', function (mixed $stored): void {
    $operation = new Operation(['state' => ['child_intents' => $stored]]);
    expect(fn () => app(TaskVmFamilyChildren::class)->nodeIntents($operation))->toThrow(LogicException::class);
})->with([['text'], [['named' => ['kind' => 'node.add']]]]);
