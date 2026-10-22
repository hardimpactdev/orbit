<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class SubprocessFixture
{
    /** A hook that acknowledges both executing PIDs before exposing its later side effect. */
    public const string DelayedHook = <<<'SH'
        printf '%s\n' "$$" > hook-pid
        sleep 30 &
        child=$!
        printf '%s\n' "$child" > child-pid
        printf start > start-marker
        wait "$child"
        printf later > later-marker
        SH;

    /** Prove that no listed process can execute a later side effect; zombies cannot execute. */
    public static function assertExited(int ...$pids): void
    {
        expect($pids)->not->toBeEmpty();
        foreach ($pids as $pid) {
            expect($pid)->toBeGreaterThan(1);
        }
        $deadline = microtime(true) + 0.3;
        do {
            $probe = new Process(['ps', '-o', 'stat=', '-p', implode(',', $pids)]);
            $probe->run();
            expect($probe->getExitCode())->toBeIn([0, 1]);
            expect($probe->getErrorOutput())->toBe('');
            $alive = array_values(array_filter(
                explode("\n", trim($probe->getOutput())),
                static fn (string $state): bool => $state !== '' && ! str_starts_with(trim($state), 'Z'),
            ));
            if ($alive === []) {
                break;
            }
            usleep(1_000);
        } while (microtime(true) < $deadline);

        expect($alive)->toBe([], 'Fixture processes are still executable: '.implode(',', $pids));
    }

    public static function line(Process $server): string
    {
        self::waitUntil($server, static fn (): bool => str_contains($server->getOutput(), "\n"));
        $line = trim(explode("\n", $server->getOutput(), 2)[0]);

        if (! $server->isRunning()) {
            throw new RuntimeException('Fixture server exited before accepting a connection: '.$server->getErrorOutput());
        }

        if ($line === '') {
            throw new RuntimeException('Fixture server printed an empty readiness line: '.$server->getErrorOutput());
        }

        return $line;
    }

    /** @param Closure(): bool $condition */
    public static function waitUntil(Process $server, Closure $condition): void
    {
        if ($server->getTimeout() === null) {
            throw new RuntimeException('Fixture server must have a timeout before waiting for readiness.');
        }

        while (true) {
            try {
                $server->checkTimeout();
            } catch (ProcessTimedOutException $exception) {
                throw new RuntimeException(
                    "Fixture server timed out before readiness.\nStdout:\n".$server->getOutput()."\nStderr:\n".$server->getErrorOutput(),
                    previous: $exception,
                );
            }
            $running = $server->isRunning();

            if ($condition()) {
                return;
            }

            if (! $running) {
                throw new RuntimeException('Fixture server exited before readiness: '.$server->getErrorOutput());
            }

            usleep(10_000);
        }
    }
}
