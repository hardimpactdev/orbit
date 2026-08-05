<?php

declare(strict_types=1);

use App\Services\Tools\ManagedToolShell;
use App\Tools\HermesTool;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Re-tokenize a command string the way bash would, returning argv words.
 *
 * @return list<string>
 */
function managed_tool_shell_words(string $commandLine): array
{
    $process = new Process([
        'bash',
        '-c',
        'eval "set -- $1"; printf \'%s\\0\' "$@"',
        'tokenize',
        $commandLine,
    ]);
    $process->mustRun();

    $words = array_values(array_filter(
        explode("\0", $process->getOutput()),
        static fn (string $word): bool => $word !== '',
    ));

    /** @var list<string> $words */
    return $words;
}

/**
 * Extract the single bash -lc script argument from a relatedProcess command.
 */
function managed_tool_bash_lc_script(string $commandLine): string
{
    $words = managed_tool_shell_words($commandLine);
    $lcIndex = array_search('-lc', $words, true);

    expect($lcIndex)
        ->not
        ->toBeFalse()
        ->and(isset($words[$lcIndex + 1]))
        ->toBeTrue()
        ->and(array_key_exists($lcIndex + 2, $words))
        ->toBeFalse('bash -lc must receive exactly one script argument after tokenization');

    return $words[$lcIndex + 1];
}

it('double-quotes missing messages so snippets stay valid inside a single-quoted bash -lc argument', function (): void {
    $snippet = ManagedToolShell::requireNonEmptySecretFromFile(
        fileVar: '${PASSWORD_FILE}',
        targetVar: 'PASSWORD',
        missingMessage: 'hermes dashboard password missing',
    );

    expect($snippet)
        ->toContain('echo "hermes dashboard password missing"')
        ->not->toContain("echo 'hermes dashboard password missing'");
});

it('tokenizes Hermes relatedProcess as one full bash -lc script that parses', function (): void {
    $command = new HermesTool()->relatedProcess()['command'];
    $words = managed_tool_shell_words($command);
    $script = managed_tool_bash_lc_script($command);

    expect($words)
        ->toContain('bash')
        ->toContain('-lc')
        ->and($script)
        ->toContain('set -euo pipefail')
        ->toContain('hermes dashboard password missing')
        ->toContain('hermes dashboard secret missing')
        ->toContain('export HERMES_DASHBOARD_BASIC_AUTH_PASSWORD="${PASSWORD}"')
        ->toContain('export HERMES_DASHBOARD_BASIC_AUTH_SECRET="${SECRET}"')
        ->toContain('exec hermes dashboard --host 0.0.0.0 --port 8080 --no-open');

    $syntax = new Process(['bash', '-n', '-c', $script]);
    $syntax->run();

    expect($syntax->getExitCode())
        ->toBe(0, $syntax->getErrorOutput());
});
