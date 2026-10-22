<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\DatabaseServers\NativeDatabaseRemovalLock;
use Symfony\Component\Process\Process;

it('locks the complete server set and releases partial acquisition and failed operations', function (): void {
    $directory = config()->string('orbit.home').'/locks/removal-test-'.bin2hex(random_bytes(4));
    $owner = new NativeDatabaseRemovalLock($directory);
    $contender = new NativeDatabaseRemovalLock($directory);
    $entered = false;

    expect(fn () => $owner->run([3, 2, 2], function () use ($contender, &$entered): never {
        expect(fn () => $contender->run([1, 2], function () use (&$entered): void {
            $entered = true;
        }))->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('database.server_in_use'));
        expect($contender->run([1], static fn (): string => 'free'))->toBe('free');
        expect($contender->run([4], static fn (): string => 'independent'))->toBe('independent');
        throw new RuntimeException('Injected removal failure.');
    }))->toThrow(RuntimeException::class, 'Injected removal failure.');

    expect($entered)->toBeFalse();
    expect($contender->run([2, 3], static fn (): string => 'released'))->toBe('released');
});

it('releases removal locks when their Gateway process dies so retry can recover', function (): void {
    $directory = config()->string('orbit.home').'/locks/removal-crash-'.bin2hex(random_bytes(4));
    $script = <<<'PHP'
        require $argv[1];
        $lock = new App\Infrastructure\DatabaseServers\NativeDatabaseRemovalLock($argv[2]);
        $lock->run([11, 12], static function (): void {
            echo "locked\n";
            flush();
            sleep(60);
        });
        PHP;
    $process = new Process([PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $directory]);
    $process->setTimeout(5)->start();
    try {
        expect($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'locked')))->toBeTrue();
        $retry = new NativeDatabaseRemovalLock($directory);
        expect(fn () => $retry->run([11, 12], static fn (): bool => true))
            ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('database.server_in_use'));
        $process->stop(0, 9);
        expect($retry->run([11, 12], static fn (): string => 'recovered'))->toBe('recovered');
    } finally {
        $process->stop(0, 9);
    }
});
