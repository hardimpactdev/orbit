<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\ResolvedVp;
use Tests\Support\StandardLibraryPython;

function check_runner_directory(): string
{
    $directory = sys_get_temp_dir().'/orbit-task-check-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory.'/home');
    // Login shells load this after the check program filters its environment.
    File::put($directory.'/home/.profile', "export COMPOSER_ALLOW_SUPERUSER=1\n");

    return $directory;
}

/** Use only when command evidence, not Composer itself, is the proof. */
function check_runner_scripted_checks(): void
{
    $bin = test()->directory.'/scripted-check-bin';
    File::ensureDirectoryExists($bin);
    file_put_contents($bin.'/composer', <<<'PHP'
        #!/usr/bin/env php
        <?php
        if (array_slice($argv, 1) !== ['check']) {
            fwrite(STDERR, "Unexpected scripted check invocation\n");
            exit(64);
        }
        $configuration = json_decode(file_get_contents('composer.json'), true, flags: JSON_THROW_ON_ERROR);
        passthru($configuration['scripts']['check'], $status);
        exit($status);
        PHP
        ."\n");
    chmod($bin.'/composer', 0700);
    file_put_contents(test()->directory.'/home/.profile', 'export PATH='.escapeshellarg($bin).':"$PATH"'."\n", FILE_APPEND);
}

function check_runner_checkout(string $check, bool $realComposer = false): string
{
    if (! $realComposer) {
        check_runner_scripted_checks();
    }
    $checkout = test()->directory.'/'.bin2hex(random_bytes(6));
    new Process(['git', 'init', '--quiet', $checkout])->mustRun();
    file_put_contents($checkout.'/composer.json', json_encode(['scripts' => ['check' => $check]], JSON_THROW_ON_ERROR));
    file_put_contents($checkout.'/.gitignore', "ignored/\n");
    new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start'], $checkout)->mustRun();
    file_put_contents($checkout.'/uncommitted.php', "<?php\n");

    return $checkout;
}

/** @param  array<string, mixed>  $group */
function check_runner_instance(string $checkout, array $group = []): Instance
{
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'check-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.160', 'wireguard_ip' => '10.44.0.160', 'user' => 'orbit']);

    return test_ready_task_workspace($project, $node, ['name' => 'task-8', 'checkout_path' => $checkout, 'branch' => 'task-8'], $group);
}

function check_runner_shell(LocalShellSshExecutor $transport): LocalShellSshExecutor
{
    $transport->environment['HOME'] = test()->directory.'/home';
    $transport->environment['PATH'] = StandardLibraryPython::path((string) getenv('PATH'));

    return $transport;
}

function check_runner(SshExecutor $transport, string $vpHome = '/opt/orbit/vite-plus', ?AgentCommandRunner $probe = null): RemoteTaskCheckRunner
{
    if ($transport instanceof LocalShellSshExecutor) {
        check_runner_shell($transport);
    }
    $vp = ResolvedVp::manager($vpHome, $probe);

    return new RemoteTaskCheckRunner(new TaskWorkspaceExecutor(new DevelopmentSshExecutor(
        $transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/home/orbit/.orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/home/orbit/.orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    )), $vp, app(RepositoryPullRequestAccess::class));
}

function check_runner_wait(RemoteTaskCheckRunner $runner, Instance $instance, TaskCheckProcess $process): TaskCheckReading
{
    check_runner_wait_for_exit($process);

    return $runner->read($instance, $process);
}

/**
 * Inspect completed local work, never remote status decoding, streaming or cancellation.
 *
 * @return array{result: array<string, mixed>, output: string, gitDirectory: string}
 */
function check_runner_local_result(string $checkout, TaskCheckProcess $process): array
{
    check_runner_wait_for_exit($process);
    $git = trim(new Process(['git', '-C', $checkout, 'rev-parse', '--absolute-git-dir'])->mustRun()->getOutput());

    return [
        'result' => json_decode(File::get($git.'/orbit/check.json'), true, flags: JSON_THROW_ON_ERROR),
        'output' => File::get($git.'/orbit/check.log'),
        'gitDirectory' => $git,
    ];
}

function check_runner_wait_for_exit(TaskCheckProcess $process): void
{
    check_runner_wait_for_pid($process->pid);
}

function check_runner_wait_for_pid(int $pid): void
{
    // A pidfd becomes readable on exit, including an unreaped detached child. No status-polling sleeps are needed.
    $wait = new Process(['/usr/bin/env', 'python3', '-c', <<<'PYTHON'
        import os, select, sys
        try:
            fd = os.pidfd_open(int(sys.argv[1]))
        except ProcessLookupError:
            sys.exit(0)
        try:
            assert select.select([fd], [], [], 15)[0], 'The check did not finish.'
        finally:
            os.close(fd)
        PYTHON, (string) $pid], env: ['PATH' => StandardLibraryPython::path((string) getenv('PATH'))]);
    $wait->mustRun();
}
