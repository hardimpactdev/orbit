<?php

declare(strict_types=1);

use App\Services\SelfUpdate\ReleaseLocation;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/*
 * Stand-ins for `orbit self-update` tests. The release bytes under tests/Fixtures/SelfUpdate are the ones whose
 * checksums the recorded Gateway fixtures name; see apps/gateway/tests/Fixtures/GitHub/CliRelease/SOURCE.md.
 */

const SELF_UPDATE_OLD_BINARY = "#!/bin/sh\necho 'Orbit 0.4600.0'\n";

function self_update_release_bytes(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/SelfUpdate/'.$name));
}

/**
 * Fakes the processes self-update starts: `curl` writes the CLI release asset its URL names, and the CLI candidate
 * reports its version. Any other download fails with 404. Every command is recorded in `$this->processes`.
 *
 * @param  array<string, string>  $downloads  Bytes to serve by `<tag>/<asset>` instead of the stand-ins.
 */
function fake_self_update_processes(object $test, array $downloads = [], string $reported = 'Orbit 0.4681.0', ?Closure $onDownload = null): void
{
    $test->processes = [];

    Process::fake(static function (PendingProcess $process) use ($test, $downloads, $reported, $onDownload) {
        $command = (array) $process->command;
        $test->processes[] = $command;

        if ($command[0] === 'curl') {
            $url = (string) end($command);
            $output = $command[array_search('--output', $command, true) + 1];
            $key = basename(dirname($url)).'/'.basename($url);

            if ($onDownload instanceof Closure && ($result = $onDownload($key, $output)) !== null) {
                return $result;
            }

            $bytes = $downloads[$key] ?? (str_starts_with($url, ReleaseLocation::Default.'/cli-v0.4681.0/') ? self_update_release_bytes(basename($url)) : null);

            if ($bytes === null) {
                return Process::result(exitCode: 22, errorOutput: 'curl: (22) The requested URL returned error: 404');
            }

            file_put_contents($output, $bytes);

            return Process::result();
        }

        if (($command[1] ?? null) === '--version') {
            return Process::result(output: $reported."\n");
        }

        return Process::result(exitCode: 127);
    });
}

/** @return list<string> The commands the fake recorded, such as `curl cli-v0.4681.0/SHA256SUMS`. */
function self_update_commands(object $test): array
{
    return array_map(static fn (array $command): string => match (true) {
        $command[0] === 'curl' => 'curl '.basename(dirname((string) end($command))).'/'.basename((string) end($command)),
        ($command[1] ?? null) === '--version' => 'version',
        default => implode(' ', $command),
    }, $test->processes);
}

/**
 * @param  array<string, mixed>  $result
 * @return array<string, mixed>
 */
function self_update_step(array $result, string $step): array
{
    foreach ($result['steps'] as $candidate) {
        if ($candidate['step'] === $step) {
            return $candidate;
        }
    }

    throw new RuntimeException("The result has no {$step} step.");
}
