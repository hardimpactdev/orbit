<?php

declare(strict_types=1);

use App\Domain\Logs\LogReadLimit;

it('returns every complete journal line in time order', function (string $reversed, string $ordered): void {
    expect(LogReadLimit::journalInTimeOrder($reversed))->toBe($ordered);
})->with([
    'one entry' => ["only\n", "only\n"],
    'two entries' => ["second\nfirst\n", "first\nsecond\n"],
    'cut final line' => ["second\nfir", "second\n"],
    'empty journal' => ['', ''],
]);
