<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('scans only added source lines and ignores an unchanged secret-shaped fixture', function (): void {
    $workspace = secret_scan_test_workspace('added-lines');

    try {
        $privateKeyHeader = '-----BEGIN '.'PRIVATE KEY-----';
        file_put_contents("{$workspace}/fixture.txt", $privateKeyHeader."\nunchanged fixture\n");
        secret_scan_test_git($workspace, ['add', 'fixture.txt']);
        secret_scan_test_git($workspace, ['commit', '-m', 'Seed fixture']);
        secret_scan_test_git($workspace, ['checkout', '-b', 'feature']);
        file_put_contents("{$workspace}/fixture.txt", $privateKeyHeader."\nchanged safe fixture\n");
        secret_scan_test_git($workspace, ['add', 'fixture.txt']);
        secret_scan_test_git($workspace, ['commit', '-m', 'Safe change']);

        $safe = secret_scan_test_run($workspace);

        expect($safe->getExitCode())->toBe(0, $safe->getErrorOutput());

        file_put_contents("{$workspace}/leak.txt", $privateKeyHeader."\n");
        secret_scan_test_git($workspace, ['add', 'leak.txt']);
        secret_scan_test_git($workspace, ['commit', '-m', 'Leaked key']);

        $blocked = secret_scan_test_run($workspace);

        expect($blocked->getExitCode())
            ->toBe(2)
            ->and($blocked->getErrorOutput())
            ->toContain('leak.txt')
            ->toContain('private-key');
    } finally {
        secret_scan_test_remove($workspace);
    }
});

it('blocks high-confidence tokens and secret assignments in compact session state', function (): void {
    $workspace = secret_scan_test_workspace('session');

    try {
        secret_scan_test_git($workspace, ['checkout', '-b', 'feature']);
        $token = 'xoxb-'.str_repeat('a', 24);
        file_put_contents("{$workspace}/.orbit/feedback.jsonl", "{\"raw_text\":\"{$token}\"}\n");

        $tokenBlock = secret_scan_test_run($workspace);

        expect($tokenBlock->getExitCode())
            ->toBe(2)
            ->and($tokenBlock->getErrorOutput())
            ->toContain('slack-token');

        file_put_contents(
            "{$workspace}/.orbit/feedback.jsonl",
            '{"raw_text":"password = \\"'.str_repeat('p', 20).'\\""}'."\n",
        );

        $assignmentBlock = secret_scan_test_run($workspace);

        expect($assignmentBlock->getExitCode())
            ->toBe(2)
            ->and($assignmentBlock->getErrorOutput())
            ->toContain('secret-assignment');
    } finally {
        secret_scan_test_remove($workspace);
    }
});

it('blocks common quoted and unquoted secret assignments in session state', function (string $assignment): void {
    $workspace = secret_scan_test_workspace('assignment-'.hash('crc32b', $assignment));

    try {
        secret_scan_test_git($workspace, ['checkout', '-b', 'feature']);
        file_put_contents("{$workspace}/.orbit/feedback.jsonl", $assignment."\n");

        $process = secret_scan_test_run($workspace);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('secret-assignment');
    } finally {
        secret_scan_test_remove($workspace);
    }
})->with([
    'client secret' => ['client_secret="'.str_repeat('c', 24).'"'],
    'access token' => ['access_token: "'.str_repeat('d', 24).'"'],
    'hyphenated api key' => ['api-key="'.str_repeat('e', 24).'"'],
    'unquoted token' => ['token='.str_repeat('f', 24)],
    'prefixed database password' => ['DB_PASSWORD='.str_repeat('g', 24)],
    'prefixed OAuth client secret' => ['OAUTH_CLIENT_SECRET='.str_repeat('h', 24)],
]);

it('keeps broad assignment heuristics out of generic candidate diffs', function (): void {
    $workspace = secret_scan_test_workspace('candidate-placeholder-assignment');

    try {
        secret_scan_test_git($workspace, ['checkout', '-b', 'feature']);
        file_put_contents("{$workspace}/example.txt", "client_secret=replace-with-client-secret-value\n");
        secret_scan_test_git($workspace, ['add', 'example.txt']);
        secret_scan_test_git($workspace, ['commit', '-m', 'Document placeholder']);

        $process = secret_scan_test_run($workspace);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    } finally {
        secret_scan_test_remove($workspace);
    }
});

it('blocks calibrated high-confidence formats in added candidate lines', function (
    #[\SensitiveParameter]
    string $secret,
    string $rule,
): void {
    $workspace = secret_scan_test_workspace('candidate-'.$rule);

    try {
        secret_scan_test_git($workspace, ['checkout', '-b', 'feature']);
        file_put_contents("{$workspace}/leak.txt", $secret."\n");
        secret_scan_test_git($workspace, ['add', 'leak.txt']);
        secret_scan_test_git($workspace, ['commit', '-m', 'Add leak']);

        $process = secret_scan_test_run($workspace);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('leak.txt')
            ->toContain($rule);
    } finally {
        secret_scan_test_remove($workspace);
    }
})->with([
    'aws access key' => ['AKIA'.str_repeat('D', 16), 'aws-access-key'],
    'github oauth token' => ['gho_'.str_repeat('e', 24), 'github-token'],
    'Laravel app key' => ['APP_KEY='.'base64:'.str_repeat('F', 43).'=', 'laravel-app-key'],
    'encrypted private key' => ['-----BEGIN '.'ENCRYPTED PRIVATE KEY-----', 'private-key'],
]);

it('scans every regular file in a constructed archive tree and rejects links', function (): void {
    $workspace = secret_scan_test_workspace('tree');
    $tree = "{$workspace}/archive-tree";
    mkdir("{$tree}/agent-sessions/codex/raw", recursive: true);

    try {
        $token = 'gho_'.str_repeat('f', 24);
        file_put_contents("{$tree}/agent-sessions/codex/raw/rollout.jsonl", "{\"token\":\"{$token}\"}\n");
        $blocked = secret_scan_test_run($workspace, $tree);

        expect($blocked->getExitCode())
            ->toBe(2)
            ->and($blocked->getErrorOutput())
            ->toContain('agent-sessions/codex/raw/rollout.jsonl')
            ->toContain('github-token');

        unlink("{$tree}/agent-sessions/codex/raw/rollout.jsonl");
        file_put_contents("{$workspace}/external.txt", "external\n");
        symlink("{$workspace}/external.txt", "{$tree}/agent-sessions/codex/raw/rollout.jsonl");
        $linked = secret_scan_test_run($workspace, $tree);

        expect($linked->getExitCode())
            ->toBe(2)
            ->and($linked->getErrorOutput())
            ->toContain('unsafe-symlink');
    } finally {
        secret_scan_test_remove($workspace);
    }
});

function secret_scan_test_workspace(string $suffix): string
{
    $workspace = sys_get_temp_dir().'/orbit-secret-scan-'.$suffix.'-'.bin2hex(random_bytes(6));
    mkdir("{$workspace}/.orbit", recursive: true);
    secret_scan_test_git($workspace, ['init', '--initial-branch=main']);
    secret_scan_test_git($workspace, ['config', 'user.email', 'orbit@example.test']);
    secret_scan_test_git($workspace, ['config', 'user.name', 'Orbit Test']);
    file_put_contents("{$workspace}/README.md", "# Fixture\n");
    secret_scan_test_git($workspace, ['add', 'README.md']);
    secret_scan_test_git($workspace, ['commit', '-m', 'Initial']);

    return $workspace;
}

function secret_scan_test_run(string $workspace, ?string $tree = null): Process
{
    $arguments = [
        repo_path('bin/orbit-secret-scan'),
        "--worktree={$workspace}",
        "--orbit-dir={$workspace}/.orbit",
    ];

    if ($tree !== null) {
        $arguments[] = "--tree={$tree}";
    }

    $process = new Process($arguments, $workspace);
    $process->run();

    return $process;
}

/**
 * @param list<string> $arguments
 */
function secret_scan_test_git(string $cwd, array $arguments): string
{
    $process = new Process(['git', ...$arguments], $cwd);
    $process->mustRun();

    return trim($process->getOutput());
}

function secret_scan_test_remove(string $workspace): void
{
    if (str_contains($workspace, '/orbit-secret-scan-')) {
        new Process(['rm', '-rf', $workspace])->run();
    }
}
