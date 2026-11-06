<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckIntent;

it('states fails_on_base for every command deliverable of the admitted check and leaves the others as stored', function (): void {
    $stored = [
        ['id' => 'tests', 'type' => 'command', 'description' => 'Tests pass.', 'command' => 'vendor/bin/pest', 'directory' => '.'],
        ['id' => 'fails', 'type' => 'command', 'description' => 'Fails first.', 'command' => 'vendor/bin/pest a', 'directory' => '.', 'fails_on_base' => true, 'paths' => ['tests/a.php']],
        ['id' => 'explicit', 'type' => 'command', 'description' => 'Explicit.', 'command' => 'true', 'directory' => '.', 'fails_on_base' => false],
        ['id' => 'file', 'type' => 'file', 'description' => 'A file.', 'path' => 'docs/a.md', 'change' => 'any'],
        ['id' => 'review', 'type' => 'review', 'description' => 'Reviewed.'],
    ];

    $intent = TaskCheckIntent::withFailsOnBase($stored);

    expect($intent[0])->toBe([...$stored[0], 'fails_on_base' => false])
        ->and($intent[1])->toBe($stored[1])
        ->and($intent[2])->toBe($stored[2])
        ->and($intent[3])->toBe($stored[3])
        ->and($intent[4])->toBe($stored[4])
        ->and(TaskCheckIntent::withFailsOnBase([]))->toBe([]);
});
