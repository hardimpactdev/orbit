<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Runs `tools/ci-runners/setup.sh register NAME TOKEN` against an `incus` stub. The stub records each call, reports
 * the runner as unregistered unless `FAKE_REGISTERED` is set, and saves the stdin of the `config.sh` call.
 *
 * @return array{process:Process,calls:list<string>,stdin:string}
 */
function registerCiRunner(string $name, bool $registered = false): array
{
    $root = temporaryPath('orbit-ci-runner-setup-', 5);
    mkdir($root.'/bin', 0o755, true);
    file_put_contents($root.'/bin/incus', <<<'SH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_ROOT/calls"
case "$*" in
  *" test -f /home/runner/actions-runner/.runner") [ -n "${FAKE_REGISTERED:-}" ] ;;
  *" id -u runner" | *" id -g runner") echo 1000 ;;
  *config.sh*) cat > "$FAKE_ROOT/stdin" ;;
esac
SH);
    chmod($root.'/bin/incus', 0o755);
    $process = new Process(['bash', dirname(__DIR__, 5).'/tools/ci-runners/setup.sh', 'register', $name, 'registration-token'], env: [
        'PATH' => $root.'/bin:'.getenv('PATH'),
        'FAKE_ROOT' => $root,
        'FAKE_REGISTERED' => $registered ? '1' : '',
    ], timeout: 10);
    $process->run();

    return [
        'process' => $process,
        'calls' => file($root.'/calls', FILE_IGNORE_NEW_LINES) ?: [],
        'stdin' => is_file($root.'/stdin') ? (string) file_get_contents($root.'/stdin') : '',
    ];
}

/** @param list<string> $calls */
function ciRunnerConfigCall(array $calls): string
{
    $configs = array_values(array_filter($calls, static fn (string $call): bool => str_contains($call, 'config.sh')));
    expect($configs)->toHaveCount(1);

    return $configs[0];
}

it('registers each shard runner with the organization labels, and Sabre containers with sabre too', function (string $name, string $labels): void {
    ['process' => $process, 'calls' => $calls, 'stdin' => $stdin] = registerCiRunner($name);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    $config = ciRunnerConfigCall($calls);
    expect($config)->toEndWith("config https://github.com/hardimpactdev {$name} {$labels}")
        ->and($stdin)->toBe("registration-token\n");
    // The token reaches config.sh on stdin, never in the argv of incus.
    foreach ($calls as $call) {
        expect($call)->not->toContain('registration-token');
    }
})->with([
    'Sabre' => ['ci-sabre-3', 'self-hosted,Linux,X64,hardimpact,orbit-shard,sabre'],
    'Shark' => ['ci-shark-4', 'self-hosted,Linux,X64,hardimpact,orbit-shard'],
    'Beast' => ['ci-beast-1', 'self-hosted,Linux,X64,hardimpact,orbit-shard'],
]);

it('never reconfigures a registered runner, so its labels stay', function (): void {
    ['process' => $process, 'calls' => $calls] = registerCiRunner('ci-sabre-1', registered: true);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    foreach ($calls as $call) {
        expect($call)->not->toContain('config.sh');
    }
});
