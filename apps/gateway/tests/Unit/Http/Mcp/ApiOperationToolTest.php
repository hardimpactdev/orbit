<?php

declare(strict_types=1);

use App\Http\Mcp\ApiOperationTool;

/** docs/reference/mcp.mdx#operations: the follow hint of a started Operation is the record's own poll interval. */
it('hints the poll interval of the started record', function (?int $seconds): void {
    $started = (new ReflectionMethod(ApiOperationTool::class, 'startedOperation'))
        ->invoke(null, ['data' => ['id' => 42, 'status' => 'waiting', 'poll_after_seconds' => $seconds], 'meta' => ['request_id' => 'r']]);

    expect($started['poll'])->toBe(['tool' => 'operation-show', 'after_seconds' => $seconds])
        ->and($started['meta'])->toBe(['request_id' => 'r']);
})->with([
    'waiting for a place' => [20],
    'queued' => [2],
    'a keyed replay of an ended Operation' => [null],
]);
