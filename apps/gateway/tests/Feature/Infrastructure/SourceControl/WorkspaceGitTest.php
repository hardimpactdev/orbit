<?php

declare(strict_types=1);

use App\Domain\GitHub\GitReadEnvironment;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\SourceControl\WorkspaceGit;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\LinuxHost;
use Tests\Support\PlantedGitConfig;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-workspace-git-'.Str::uuid();
    mkdir($this->root, 0o700, true);
    $this->root = realpath($this->root) ?: $this->root;
    $this->origin = $this->root.'/origin.git';
    $this->checkout = $this->root.'/checkout';
    workspace_git_run(['git', 'init', '--quiet', '--bare', '-b', 'main', $this->origin]);
    workspace_git_run(['git', 'init', '--quiet', '-b', 'main', $this->checkout]);
    workspace_git_commit($this->checkout, 'Base');
    workspace_git_run(['git', '-C', $this->checkout, 'push', '--quiet', $this->origin, 'HEAD:refs/heads/main']);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->root);
});

/** @param list<string> $arguments */
function workspace_git_run(array $arguments, ?string $cwd = null): string
{
    return trim(new Process($arguments, $cwd)->mustRun()->getOutput());
}

function workspace_git_commit(string $repository, string $message): string
{
    workspace_git_run(['git', '-C', $repository, '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', $message]);

    return workspace_git_run(['git', '-C', $repository, 'rev-parse', 'HEAD']);
}

/**
 * Runs a token-bearing program the way the Gateway sends it: the read preamble with a token, the worker preamble,
 * and the network preamble.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function workspace_git_program(string $body, array $arguments, array $environment = [], ?string $worker = null): Process
{
    $script = GitReadScript::for(GitReadEnvironment::forGitHubToken('ghs_workspace'), WorkspaceGit::workerPreamble($worker).WorkspaceGit::networkPreamble().$body);
    $input = $script->protectedInput instanceof ProtectedInput ? stream_get_contents($script->protectedInput->stream()) : $script->input;
    $process = new Process(['bash', '-seu', '--', ...$arguments], null, $environment, $input);
    $process->run();

    return $process;
}

it('borrows objects only from the Git directory the Gateway names, never from a planted commondir', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }
    $planted = $this->root.'/planted';
    workspace_git_run(['git', 'init', '--quiet', $planted]);
    file_put_contents($this->checkout.'/.git/commondir', $planted."/.git\n");
    // Git itself follows the planted file, so asking Git for the common directory would be redirected.
    expect(workspace_git_run(['git', '-C', $this->checkout, 'rev-parse', '--path-format=absolute', '--git-common-dir']))->toBe($planted.'/.git');

    $process = workspace_git_program(<<<'BASH'
        isolated_repository "$1/.git"
        cat -- "$scratch/repository.git/objects/info/alternates"
        BASH, [$this->checkout]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(trim($process->getOutput()))->toBe($this->checkout.'/.git/objects');
});

it('refuses a Git directory that is not the exact real directory the Gateway names', function (Closure $path): void {
    if (LinuxHost::delegate($this)) {
        return;
    }
    symlink($this->checkout.'/.git', $this->root.'/link.git');
    mkdir($this->root.'/linked/.git', 0o700, true);
    symlink($this->checkout.'/.git/objects', $this->root.'/linked/.git/objects');

    $process = workspace_git_program(<<<'BASH'
        isolated_repository "$1"
        printf 'borrowed\n'
        BASH, [$path($this->root, $this->checkout)]);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->not->toContain('borrowed');
})->with([
    'a symlink to the Git directory' => [static fn (string $root, string $checkout): string => $root.'/link.git'],
    'a path that does not end in .git' => [static fn (string $root, string $checkout): string => $checkout],
    'a path through ..' => [static fn (string $root, string $checkout): string => $checkout.'/../checkout/.git'],
    'an objects directory that is a symlink' => [static fn (string $root, string $checkout): string => $root.'/linked/.git'],
]);

it('fetches with a token through an alternates chain without starting Git in an alternate or a planted command', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }
    $chain = $this->root.'/chain';
    $markers = $this->root.'/markers';
    workspace_git_run(['git', 'init', '--quiet', $chain]);
    workspace_git_commit($chain, 'Chained');
    // The worker can write both the checkout's alternates and the config of every repository the chain names.
    file_put_contents($this->checkout.'/.git/objects/info/alternates', $chain."/.git/objects\n");
    PlantedGitConfig::plant($chain, $markers, [$this->origin]);
    PlantedGitConfig::plant($this->checkout, $markers, [$this->origin]);
    $source = $this->root.'/source';
    workspace_git_run(['git', 'clone', '--quiet', $this->origin, $source]);
    $next = workspace_git_commit($source, 'Next');
    workspace_git_run(['git', '-C', $source, 'push', '--quiet', 'origin', 'HEAD:refs/heads/main']);
    $trace = $this->root.'/trace';

    $process = workspace_git_program(
        'isolated_fetch "$1" "$1/.git" "$2" 0 +refs/heads/main:refs/remotes/origin/main'."\n",
        [$this->checkout, $this->origin],
        ['GIT_TRACE' => $trace],
    );

    $log = (string) file_get_contents($trace);
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(workspace_git_run(['git', '-C', $this->checkout, 'rev-parse', 'refs/remotes/origin/main']))->toBe($next)
        ->and($log)->toContain('fetch --quiet --no-tags')
        ->and($log)->not->toContain('for-each-ref')
        ->and(PlantedGitConfig::markers($markers))->toBe([]);
});

it('lets only the worker read the private repository for the local copy', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }
    $report = $this->root.'/report';

    // The local copy is replaced by a probe, so the test needs no sudo: it reads what the worker would be given.
    $process = workspace_git_program(<<<'BASH'
        workspace_git() {
            getfacl -cp -- "$scratch" > "$report"
        }
        report=$3
        isolated_fetch "$1" "$1/.git" "$2" 0 +refs/heads/main:refs/remotes/origin/main
        BASH, [$this->checkout, $this->origin, $report], worker: 'nobody');

    $lines = file($report, FILE_IGNORE_NEW_LINES) ?: [];
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($lines)->toContain('user:nobody:r-x')
        ->and($lines)->toContain('group::---')
        ->and($lines)->toContain('other::---');
})->group('privileged');
