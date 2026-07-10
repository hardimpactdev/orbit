<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('derives the minimum acceptance venue from changed files', function (array $files, string $venue): void {
    require_once repo_path('bin/orbit-loop-contract.php');

    expect(orbitLoopAcceptanceVenue($files))->toBe($venue);
})->with([
    'docs only' => [['apps/docs/content/mission.md'], 'automated'],
    'test only' => [['apps/cli/tests/Feature/Commands/FooTest.php'], 'automated'],
    'repository executable' => [['bin/orbit-example'], 'retained-incus'],
    'cli command' => [['apps/cli/app/Commands/FooCommand.php'], 'retained-incus'],
    'node runtime' => [['apps/gateway/app/Actions/Node/RepairNode.php'], 'retained-incus'],
    'gateway frontend' => [['apps/gateway/resources/js/app.js'], 'browser'],
    'native mac app' => [['apps/macos/src/main.rs'], 'host-macos'],
]);

it('records user acceptance against the clean feature and current main tips', function (): void {
    $fixture = acceptance_test_workspace('user', 'apps/cli/app/Commands/FooCommand.php');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer 100 - observable',
            venue: 'retained-incus',
        );

        $process = acceptance_test_run(
            $fixture,
            ['accept', '--actor=user', '--source-ref=codex://threads/example#acceptance-1'],
            "Looks correct; accept.\n",
        );

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $loop = (string) file_get_contents("{$fixture}/.orbit/loop.md");
        $events = acceptance_test_feedback($fixture);

        expect($loop)
            ->toContain('- State: accepted')
            ->toContain('- Acceptance: accepted - user @ codex://threads/example#acceptance-1')
            ->toContain('- Accepted feature tip: '.acceptance_test_git($fixture, ['rev-parse', 'HEAD']))
            ->toContain('- Accepted main tip: '.acceptance_test_git($fixture, ['rev-parse', 'main']))
            ->not
            ->toContain('Looks correct; accept.')
            ->and($events)
            ->toHaveCount(1)
            ->and($events[0])
            ->toMatchArray([
                'type' => 'feedback.recorded',
                'raw_text' => 'Looks correct; accept.',
                'session_ref' => 'codex://threads/example#acceptance-1',
                'surface' => 'acceptance.retained-incus',
                'actionable' => false,
            ]);
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('requires reviewer-confirmed non-observable behavior before automatically accepting repository tooling', function (): void {
    $observable = acceptance_test_workspace('automated-blocked', 'bin/orbit-observable');
    $nonObservable = acceptance_test_workspace('automated-pass', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $observable,
            state: 'accept',
            review: 'passed - reviewer 1 - observable',
            venue: 'automated',
        );
        acceptance_test_seed_loop(
            $nonObservable,
            state: 'accept',
            review: 'passed - reviewer 2 - non-observable',
            venue: 'automated',
        );

        $blocked = acceptance_test_run($observable, ['accept', '--actor=automated']);
        $accepted = acceptance_test_run($nonObservable, ['accept', '--actor=automated']);

        expect($blocked->getExitCode())
            ->toBe(2)
            ->and($blocked->getErrorOutput())
            ->toContain('reviewer-confirmed non-observable')
            ->and($accepted->getExitCode())
            ->toBe(0, $accepted->getErrorOutput())
            ->and((string) file_get_contents("{$nonObservable}/.orbit/loop.md"))
            ->toContain('- Acceptance: accepted - automated - reviewer-confirmed non-observable')
            ->toContain('- State: accepted');
    } finally {
        acceptance_test_remove($observable);
        acceptance_test_remove($nonObservable);
    }
});

it('blocks acceptance while actionable feedback is unresolved', function (): void {
    $fixture = acceptance_test_workspace('unresolved-feedback', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - non-observable',
            venue: 'automated',
        );
        $event = [
            'schema_version' => 1,
            'type' => 'feedback.recorded',
            'id' => 'feedback-unresolved',
            'recorded_at' => '2026-07-10T18:00:00Z',
            'raw_text' => 'The command still appears frozen.',
            'session_ref' => 'codex://threads/example#feedback',
            'candidate_commit' => acceptance_test_git($fixture, ['rev-parse', 'HEAD']),
            'surface' => 'cli.progress',
            'actionable' => true,
            'context' => [],
            'evidence' => [],
        ];
        file_put_contents(
            "{$fixture}/.orbit/feedback.jsonl",
            json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );

        $process = acceptance_test_run($fixture, ['accept', '--actor=automated']);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('unresolved actionable feedback: feedback-unresolved')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- Acceptance: pending')
            ->toContain('- State: accept');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('rejects all nonignored untracked files before readiness or acceptance', function (): void {
    $fixture = acceptance_test_workspace('untracked', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - non-observable',
            venue: 'automated',
        );
        file_put_contents("{$fixture}/forgotten.php", "<?php\n");

        $ready = acceptance_test_run($fixture, ['ready', '--venue=automated']);

        expect($ready->getExitCode())
            ->toBe(2)
            ->and($ready->getErrorOutput())
            ->toContain('forgotten.php')
            ->toContain('accepted HEAD');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('requires reviewer PASS to be bound to the exact candidate HEAD', function (): void {
    $fixture = acceptance_test_workspace('reviewed-tip', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'prove',
            review: 'passed - reviewer - non-observable',
            venue: 'automated',
        );
        file_put_contents("{$fixture}/bin/orbit-later", "later\n");
        acceptance_test_git($fixture, ['add', 'bin/orbit-later']);
        acceptance_test_git($fixture, ['commit', '-m', 'Move candidate after review']);

        $ready = acceptance_test_run($fixture, ['ready', '--venue=automated']);

        expect($ready->getExitCode())
            ->toBe(2)
            ->and($ready->getErrorOutput())
            ->toContain('reviewed feature tip does not equal candidate HEAD')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- State: prove')
            ->toContain('- Acceptance: pending');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('invalidating accepted feedback resets the reviewer identity for the FIX delta', function (): void {
    $fixture = acceptance_test_workspace('review-reset', 'bin/orbit-example');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - non-observable',
            venue: 'automated',
        );
        $accepted = acceptance_test_run($fixture, ['accept', '--actor=automated']);
        expect($accepted->getExitCode())->toBe(0, $accepted->getErrorOutput());

        $invalidated = acceptance_test_run($fixture, ['invalidate', '--reason=UX correction requested']);

        expect($invalidated->getExitCode())
            ->toBe(0, $invalidated->getErrorOutput())
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- Review: fix - acceptance invalidated - UX correction requested')
            ->toContain('- Reviewed feature tip: none')
            ->toContain('- State: build');
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('requires main integration and a fresh proof and acceptance when main moves', function (): void {
    $fixture = acceptance_test_workspace('reprove', 'apps/cli/app/Commands/FooCommand.php');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - observable',
            venue: 'retained-incus',
        );
        $accepted = acceptance_test_run(
            $fixture,
            ['accept', '--actor=user', '--source-ref=codex://threads/example#acceptance'],
            "Accepted.\n",
        );
        expect($accepted->getExitCode())->toBe(0, $accepted->getErrorOutput());
        $acceptedLoop = (string) file_get_contents("{$fixture}/.orbit/loop.md");

        acceptance_test_git($fixture, ['checkout', 'main']);
        file_put_contents("{$fixture}/README.md", "# Main moved\n");
        acceptance_test_git($fixture, ['add', 'README.md']);
        acceptance_test_git($fixture, ['commit', '-m', 'Move main']);
        acceptance_test_git($fixture, ['checkout', 'feature']);

        $show = acceptance_test_run($fixture, ['show', '--json']);
        $status = json_decode($show->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($status)->toHaveKey('status', 'reproof-required');

        $reprove = acceptance_test_run($fixture, ['reprove', '--verification-ref=focused-proof-after-main-move']);

        expect($reprove->getExitCode())
            ->toBe(2)
            ->and($reprove->getErrorOutput())
            ->toContain('integrate main into the feature')
            ->toContain('PROVE and ACCEPT')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toBe($acceptedLoop);
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('rejects unsafe user acceptance source references before changing durable state', function (): void {
    $fixture = acceptance_test_workspace('unsafe-source', 'apps/cli/app/Commands/FooCommand.php');

    try {
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - observable',
            venue: 'retained-incus',
        );
        $before = (string) file_get_contents("{$fixture}/.orbit/loop.md");
        $token = 'gho_'.str_repeat('a', 24);
        $process = acceptance_test_run(
            $fixture,
            ['accept', '--actor=user', "--source-ref=codex://threads/example?token={$token}"],
            "Accept.\n",
        );

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('safe Codex or Solo source reference')
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toBe($before)
            ->and("{$fixture}/.orbit/feedback.jsonl")
            ->not->toBeFile();
    } finally {
        acceptance_test_remove($fixture);
    }
});

it('blocks high-confidence candidate secrets before acceptance', function (
    #[\SensitiveParameter]
    string $secret,
    string $rule,
): void {
    $fixture = acceptance_test_workspace('candidate-secret-'.$rule, 'bin/orbit-example');

    try {
        file_put_contents("{$fixture}/bin/orbit-example", $secret."\n");
        acceptance_test_git($fixture, ['add', 'bin/orbit-example']);
        acceptance_test_git($fixture, ['commit', '-m', 'Add secret-shaped candidate']);
        acceptance_test_seed_loop(
            $fixture,
            state: 'accept',
            review: 'passed - reviewer - non-observable',
            venue: 'automated',
        );

        $process = acceptance_test_run($fixture, ['accept', '--actor=automated']);

        expect($process->getExitCode())
            ->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain($rule)
            ->and((string) file_get_contents("{$fixture}/.orbit/loop.md"))
            ->toContain('- Acceptance: pending');
    } finally {
        acceptance_test_remove($fixture);
    }
})->with([
    'aws access key' => ['AKIA'.str_repeat('A', 16), 'aws-access-key'],
    'github oauth token' => ['gho_'.str_repeat('b', 24), 'github-token'],
    'Laravel app key' => ['APP_KEY='.'base64:'.str_repeat('C', 43).'=', 'laravel-app-key'],
    'encrypted private key' => ['-----BEGIN '.'ENCRYPTED PRIVATE KEY-----', 'private-key'],
]);

function acceptance_test_workspace(string $suffix, string $changedPath): string
{
    $workspace = sys_get_temp_dir().'/orbit-acceptance-'.$suffix.'-'.bin2hex(random_bytes(6));
    mkdir($workspace, recursive: true);
    acceptance_test_git($workspace, ['init', '--initial-branch=main']);
    acceptance_test_git($workspace, ['config', 'user.email', 'orbit@example.test']);
    acceptance_test_git($workspace, ['config', 'user.name', 'Orbit Test']);
    file_put_contents("{$workspace}/README.md", "# Fixture\n");
    file_put_contents("{$workspace}/.gitignore", ".orbit/\n");
    acceptance_test_git($workspace, ['add', 'README.md', '.gitignore']);
    acceptance_test_git($workspace, ['commit', '-m', 'Initial']);
    acceptance_test_git($workspace, ['checkout', '-b', 'feature']);
    $absolute = "{$workspace}/{$changedPath}";
    mkdir(dirname($absolute), recursive: true);
    file_put_contents($absolute, "candidate\n");
    acceptance_test_git($workspace, ['add', $changedPath]);
    acceptance_test_git($workspace, ['commit', '-m', 'Candidate']);
    mkdir("{$workspace}/.orbit", recursive: true);

    return $workspace;
}

function acceptance_test_seed_loop(
    string $fixture,
    string $state,
    string $review,
    string $venue,
): void {
    $reviewedTip = acceptance_test_git($fixture, ['rev-parse', 'HEAD']);

    file_put_contents("{$fixture}/.orbit/loop.md", <<<MARKDOWN
        # Orbit Feature Loop

        - Scratchpad: solo://proj/4/scratchpad/example--1
        - Worktree: {$fixture}
        - Branch: feature

        ## Goal

        Acceptance fixture.

        ## Scope

        - Owned: fixture
        - Constraints: no manual E2E
        - Out of scope: none

        ## Proof

        - Verification:
          - focused: passed - initial focused proof
          - broader: passed - initial broader proof
          - runtime: passed - retained fixture
        - Review: {$review}
        - Reviewed feature tip: {$reviewedTip}
        - Acceptance venue: {$venue}
        - Acceptance: pending
        - Accepted feature tip: none
        - Accepted main tip: none

        ## Status

        - State: {$state}
        - Blocker: none

        ## Feedback

        - Events: `.orbit/feedback.jsonl`
        MARKDOWN);
}

/** @param list<string> $arguments */
function acceptance_test_run(string $fixture, array $arguments, string $input = ''): Process
{
    $process = new Process([
        repo_path('bin/orbit-feature-acceptance'),
        ...$arguments,
        "--cwd={$fixture}",
        "--loop={$fixture}/.orbit/loop.md",
    ], $fixture);
    $process->setInput($input);
    $process->run();

    return $process;
}

/** @param list<string> $arguments */
function acceptance_test_git(string $cwd, array $arguments): string
{
    $process = new Process(['git', ...$arguments], $cwd);
    $process->mustRun();

    return trim($process->getOutput());
}

/** @return list<array<string, mixed>> */
function acceptance_test_feedback(string $fixture): array
{
    $path = "{$fixture}/.orbit/feedback.jsonl";

    if (! is_file($path)) {
        return [];
    }

    return array_map(
        static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(preg_split('/\R/', trim((string) file_get_contents($path))) ?: [])),
    );
}

function acceptance_test_remove(string $fixture): void
{
    if (str_contains($fixture, '/orbit-acceptance-')) {
        new Process(['rm', '-rf', $fixture])->run();
    }
}
