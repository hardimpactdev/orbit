<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Infrastructure\Tasks\NativeTaskExecutionLock;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('serializes task work admission on independent lock handles and releases after nested failure', function (): void {
    $directory = sys_get_temp_dir().'/orbit-task-execution-'.Str::uuid();
    $lock = new NativeTaskExecutionLock($directory);
    try {
        expect(fn () => $lock->synchronized(1, function () use ($lock, $directory): void {
            $independent = fopen($directory.'/task-1.lock', 'c+');
            expect($independent)->not->toBeFalse();
            try {
                expect(flock($independent, LOCK_EX | LOCK_NB))->toBeFalse();
                expect($lock->synchronized(2, static fn (): string => 'another group'))->toBe('another group');
                $lock->synchronized(1, static function (): void {
                    throw new RuntimeException('Nested admission failed.');
                });
            } finally {
                fclose($independent);
            }
        }))->toThrow(RuntimeException::class, 'Nested admission failed.');

        $independent = fopen($directory.'/task-1.lock', 'c+');
        expect($independent)->not->toBeFalse();
        try {
            expect(flock($independent, LOCK_EX | LOCK_NB))->toBeTrue()
                ->and(fileperms($directory) & 0o777)->toBe(0o700)
                ->and(fileperms($directory.'/task-1.lock') & 0o777)->toBe(0o600);
        } finally {
            flock($independent, LOCK_UN);
            fclose($independent);
        }
        expect($lock->synchronized(1, static fn (): string => 'retry'))->toBe('retry');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('bounds a waiting caller without admitting it and allows a retry after release', function (): void {
    $directory = sys_get_temp_dir().'/orbit-task-execution-'.Str::uuid();
    $owner = new NativeTaskExecutionLock($directory);
    $waiting = new NativeTaskExecutionLock($directory, waitSeconds: 0.02);
    $entered = false;
    try {
        $owner->synchronized(1, function () use ($waiting, &$entered): void {
            expect(fn () => $waiting->synchronized(1, function () use (&$entered): void {
                $entered = true;
            }))
                ->toThrow(TaskWorkspaceWaiting::class);
        });
        expect($entered)->toBeFalse()->and($waiting->synchronized(1, static fn (): string => 'retry'))->toBe('retry');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});
