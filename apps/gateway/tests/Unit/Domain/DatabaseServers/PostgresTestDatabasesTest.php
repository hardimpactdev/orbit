<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\PostgresTestDatabases;
use App\Domain\Shared\ResourceOperationException;

describe('PostgresTestDatabases', function (): void {
    it('keeps short database names and enumerates the exact serial and worker names', function (): void {
        $pool = new PostgresTestDatabases;
        expect($pool->database('mealou_dev'))->toBe('mealou_dev');
        expect($pool->names('mealou_dev_test', 2))->toBe(['mealou_dev_test', 'mealou_dev_test_test_1', 'mealou_dev_test_test_2']);
    });

    it('shortens a long primary name deterministically so every Laravel worker suffix fits', function (): void {
        $pool = new PostgresTestDatabases;
        $primary = $pool->database(str_repeat('a', 63));
        expect($primary)->toBe(str_repeat('a', 41).'_03f09f5b');
        expect($pool->names($primary.'_test', 32)[32])->toBe(str_repeat('a', 41).'_03f09f5b_test_test_32')->toHaveLength(63);
        expect($pool->database(str_repeat('a', 50)))->toBe(str_repeat('a', 50));
        expect($pool->database(str_repeat('b', 63)))->not->toBe($primary);
    });

    it('refuses an unbounded or empty pool', function (int $processes): void {
        expect(fn () => new PostgresTestDatabases()->names('app_test', $processes))->toThrow(ResourceOperationException::class, 'Test processes must be an integer from 1 through 32.');
    })->with([0, -1, 33]);
});
