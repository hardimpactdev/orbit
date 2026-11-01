<?php

declare(strict_types=1);

use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Gateway\GatewayCaddyConfigRenderer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

it('publishes one complete gateway file from distinct candidates under contention', function (): void {
    $directory = sys_get_temp_dir().'/orbit-protected-writer-'.Str::uuid();
    $path = $directory.'/generated/gateway/Caddyfile';
    $markerDirectory = $directory.'/markers';
    $releasePath = $markerDirectory.'/release';
    $characters = ['A', 'B', 'C', 'D'];
    $bytes = 1024 * 1024;
    $processes = [];

    mkdir($markerDirectory, permissions: 0o700, recursive: true);

    try {
        foreach ($characters as $index => $character) {
            $process = protected_file_writer_process($path, $character, $bytes, "{$markerDirectory}/staged-{$index}", $releasePath);
            $process->start();
            $processes[] = $process;
        }

        // Every writer holds its complete candidate until all of them have one, so the
        // candidates exist side by side whatever the scheduler does.
        foreach (array_keys($characters) as $index) {
            protected_file_writer_wait_until(
                static fn (): bool => is_file("{$markerDirectory}/staged-{$index}"),
                $processes,
                static fn (): string => "staged marker {$index}",
            );
        }

        $candidates = protected_file_writer_candidates($path);
        $candidateContents = array_map(file_get_contents(...), $candidates);
        sort($candidateContents);

        expect($candidates)->toHaveCount(4)
            ->and($candidateContents)->toBe(array_map(static fn (string $character): string => str_repeat($character, $bytes), $characters));

        file_put_contents($releasePath, 'release');

        foreach ($processes as $process) {
            protected_file_writer_wait_for_success($process);
        }

        $contents = file_get_contents($path);

        expect(is_string($contents))->toBeTrue();

        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('The contended protected file was not published.');
        }

        expect(in_array($contents[0], $characters, true))->toBeTrue();
        expect($contents)->toBe(str_repeat($contents[0], $bytes));
        expect(protected_file_writer_candidates($path))->toBe([]);
        expect(fileperms($path) & 0o777)->toBe(0o644);
        expect(fileperms(dirname($path)) & 0o777)->toBe(0o700);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.1, 9);
            }
        }

        new Filesystem()->deleteDirectory($directory);
    }
});

it('preserves a refused destination and cleans only its protected-file candidate', function (): void {
    $directory = sys_get_temp_dir().'/orbit-protected-writer-'.Str::uuid();
    $path = $directory.'/generated/gateway/Caddyfile';
    $unrelatedCandidate = $path.'.candidate.other-invocation';
    mkdir($path, permissions: 0o700, recursive: true);
    file_put_contents($path.'/original', 'original');
    file_put_contents($unrelatedCandidate, 'other');
    chmod($unrelatedCandidate, 0o600);

    try {
        set_error_handler(static fn (): bool => true);

        try {
            expect(fn () => new ProtectedFileWriter()->put($path, 'replacement', 0o644))
                ->toThrow(RuntimeException::class, "Could not install protected file [{$path}].");
        } finally {
            restore_error_handler();
        }

        expect(file_get_contents($path.'/original'))->toBe('original');
        expect(protected_file_writer_siblings($path))->toBe([$unrelatedCandidate]);
        expect(file_get_contents($unrelatedCandidate))->toBe('other');
        expect(fileperms($unrelatedCandidate) & 0o777)->toBe(0o600);
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('adapts Caddy to a listener bound only to the gateway WireGuard address', function (): void {
    $configuration = new GatewayCaddyConfigRenderer()->render(
        hostname: 'gateway.orbit',
        wireguardIp: '10.44.0.1',
        checkoutPath: '/home/orbit/orbit-gateway',
        webRoot: '/home/orbit/web',
    );
    $result = caddy_adapt($configuration);
    /** @var array{apps: array{http: array{servers: array<string, array{listen: list<string>}>}}} $adapted */
    $adapted = json_decode(json: $result->stdout, associative: true, flags: JSON_THROW_ON_ERROR);
    $listeners = Collection::make($adapted['apps']['http']['servers'])
        ->flatMap(static fn (array $server): array => $server['listen'])
        ->values()
        ->all();

    expect($result->succeeded())
        ->toBeTrue()
        ->and($listeners)
        ->toBe(['10.44.0.1:443'])
        ->not->toContain(':443', '0.0.0.0:443');
});

/**
 * Starts a writer that holds its written candidate before it installs it: the script shadows
 * `rename` in the writer's namespace, marks the candidate as staged, and waits for the release.
 */
function protected_file_writer_process(
    string $path,
    string $character,
    int $bytes,
    string $stagedPath,
    string $releasePath,
): Process {
    $script = <<<'SCRIPT'
        namespace App\Infrastructure\Files {
            function rename(string $from, string $to): bool
            {
                file_put_contents($GLOBALS['argv'][5], 'staged');

                while (! is_file($GLOBALS['argv'][6])) {
                    usleep(1_000);
                }

                return \rename($from, $to);
            }
        }

        namespace {
            require $argv[1].'/vendor/autoload.php';

            new App\Infrastructure\Files\ProtectedFileWriter()->put(
                $argv[2],
                str_repeat($argv[3], (int) $argv[4]),
                0o644,
            );
        }
        SCRIPT;
    $process = new Process([
        PHP_BINARY,
        '-r',
        $script,
        base_path(),
        $path,
        $character,
        (string) $bytes,
        $stagedPath,
        $releasePath,
    ]);
    $process->setTimeout(30);

    return $process;
}

/** @return list<string> */
function protected_file_writer_candidates(string $path): array
{
    $candidates = glob($path.'.candidate.*');

    if (! is_array($candidates)) {
        return [];
    }

    sort($candidates);

    return $candidates;
}

/** @return list<string> */
function protected_file_writer_siblings(string $path): array
{
    $siblings = glob($path.'.*');

    if (! is_array($siblings)) {
        return [];
    }

    sort($siblings);

    return $siblings;
}

function protected_file_writer_wait_for_success(Process $process): void
{
    $exitCode = $process->wait();

    if ($exitCode !== 0) {
        throw new RuntimeException(
            "Protected-file writer failed with exit code {$exitCode}: {$process->getErrorOutput()}",
        );
    }
}

/** @param list<Process> $processes */
function protected_file_writer_wait_until(Closure $condition, array $processes, Closure $state): void
{
    while (! $condition()) {
        $running = false;
        $failure = null;
        $writerStates = [];

        foreach ($processes as $index => $process) {
            try {
                $process->checkTimeout();
            } catch (ProcessTimedOutException $exception) {
                $failure = "Writer {$index} exceeded its {$exception->getExceededTimeout()} second lifetime.";
            }

            if ($process->isRunning()) {
                $running = true;
            } elseif (! $process->isSuccessful()) {
                $failure ??= 'A protected-file writer failed before the required state was observed.';
            }

            $writerStates[] = sprintf(
                'writer %d: status=%s, exit=%s, stderr=%s',
                $index,
                $process->getStatus(),
                $process->getExitCode() ?? 'none',
                $process->getErrorOutput(),
            );
        }

        if ($failure !== null || ! $running) {
            throw new RuntimeException(
                $state().'. '.($failure ?? 'All protected-file writers exited before the required state was observed.')
                .' '.implode('; ', $writerStates),
            );
        }

        usleep(1_000);
    }
}
