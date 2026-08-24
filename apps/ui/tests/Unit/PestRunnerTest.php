<?php

declare(strict_types=1);

use Launch\PestRunner;
use Symfony\Component\Process\Process;

/**
 * @return array<string, string>
 */
function launchPestRunnerGitEnv(): array
{
    return PestRunner::isolatedGitEnvironment();
}

function launchPestRunnerScratch(bool $initializeGit, bool $commit): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'launch-pest-'.bin2hex(random_bytes(8));

    mkdir($directory);
    mkdir($directory.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin', 0777, true);
    file_put_contents($directory.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pest', "<?php\n");

    if (! $initializeGit) {
        return $directory;
    }

    $init = new Process(['git', 'init'], $directory, launchPestRunnerGitEnv());
    $init->mustRun();

    if (! $commit) {
        return $directory;
    }

    $commitProcess = new Process(
        ['git', 'commit', '--allow-empty', '-m', 'baseline'],
        $directory,
        [
            ...launchPestRunnerGitEnv(),
            'GIT_AUTHOR_NAME' => 'Launch Test',
            'GIT_AUTHOR_EMAIL' => 'test@example.com',
            'GIT_COMMITTER_NAME' => 'Launch Test',
            'GIT_COMMITTER_EMAIL' => 'test@example.com',
        ],
    );
    $commitProcess->mustRun();

    return $directory;
}

function launchPestRunnerCleanup(string $directory): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($directory);
}

it('runs the full suite when there is no git repository', function () {
    $directory = launchPestRunnerScratch(initializeGit: false, commit: false);

    try {
        $runner = new PestRunner($directory);

        expect($runner->hasGitBaseline())->toBeFalse()
            ->and($runner->pestArguments())->not->toContain('--tia');
    } finally {
        launchPestRunnerCleanup($directory);
    }
});

it('runs the full suite after git init but before the first commit', function () {
    $directory = launchPestRunnerScratch(initializeGit: true, commit: false);

    try {
        $runner = new PestRunner($directory);

        expect($runner->hasGitBaseline())->toBeFalse()
            ->and($runner->pestArguments())->not->toContain('--tia');
    } finally {
        launchPestRunnerCleanup($directory);
    }
});

it('enables tia once a git baseline commit exists', function () {
    $directory = launchPestRunnerScratch(initializeGit: true, commit: true);

    try {
        $runner = new PestRunner($directory);

        expect($runner->hasGitBaseline())->toBeTrue()
            ->and($runner->shouldUseTia())->toBeTrue()
            ->and($runner->pestArguments())->toContain('--tia');
    } finally {
        launchPestRunnerCleanup($directory);
    }
});

it('does not enable tia when the project is nested in a parent repository', function () {
    $directory = launchPestRunnerScratch(initializeGit: true, commit: true);
    $projectDirectory = $directory.DIRECTORY_SEPARATOR.'apps'.DIRECTORY_SEPARATOR.'ui';
    mkdir($projectDirectory, 0777, true);

    try {
        $runner = new PestRunner($projectDirectory);

        expect($runner->hasGitBaseline())->toBeTrue()
            ->and($runner->pestArguments())->not->toContain('--tia');
    } finally {
        launchPestRunnerCleanup($directory);
    }
});

it('does not auto-add tia when a filter or path is passed', function () {
    $directory = launchPestRunnerScratch(initializeGit: true, commit: true);

    try {
        $runner = new PestRunner($directory);

        expect($runner->pestArguments(['--filter=example']))->not->toContain('--tia')
            ->and($runner->pestArguments(['--filter=example']))->toContain('--filter=example')
            ->and($runner->pestArguments(['tests/Unit/ExampleTest.php']))->not->toContain('--tia')
            ->and($runner->pestArguments(['tests/Unit/ExampleTest.php']))->toContain('tests/Unit/ExampleTest.php');
    } finally {
        launchPestRunnerCleanup($directory);
    }
});

it('creates a git baseline even when global commit.gpgsign is required', function () {
    $evilConfig = sys_get_temp_dir().DIRECTORY_SEPARATOR.'launch-evil-gitconfig-'.bin2hex(random_bytes(4));
    file_put_contents($evilConfig, "[commit]\n\tgpgsign = true\n[user]\n\tsigningkey = 0xDEADBEEF\n[gpg]\n\tprogram = /usr/bin/false\n");

    $previous = getenv('GIT_CONFIG_GLOBAL');
    putenv('GIT_CONFIG_GLOBAL='.$evilConfig);

    $directory = null;

    try {
        $directory = launchPestRunnerScratch(initializeGit: true, commit: true);
        $runner = new PestRunner($directory);

        expect($runner->hasGitBaseline())->toBeTrue()
            ->and($runner->pestArguments())->toContain('--tia');
    } finally {
        if ($previous === false) {
            putenv('GIT_CONFIG_GLOBAL');
        } else {
            putenv('GIT_CONFIG_GLOBAL='.$previous);
        }

        if (is_string($directory)) {
            launchPestRunnerCleanup($directory);
        }

        unlink($evilConfig);
    }
});
