<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\LocalShellSshExecutor;

function check_runner_directory(): string
{
    $directory = sys_get_temp_dir().'/orbit-task-check-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory.'/home');
    // Login shells load this after the check program filters its environment.
    File::put($directory.'/home/.profile', "export COMPOSER_ALLOW_SUPERUSER=1\n");

    return $directory;
}

function check_runner_checkout(string $check): string
{
    $checkout = test()->directory.'/'.bin2hex(random_bytes(6));
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    file_put_contents($checkout.'/composer.json', json_encode(['scripts' => ['check' => $check]], JSON_THROW_ON_ERROR));
    file_put_contents($checkout.'/.gitignore', "ignored/\n");
    (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start'], $checkout))->mustRun();
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

    return $transport;
}

function check_runner(SshExecutor $transport, string $vpHome = '/opt/orbit/vite-plus', ?SshExecutor $probe = null): RemoteTaskCheckRunner
{
    if ($transport instanceof LocalShellSshExecutor) {
        check_runner_shell($transport);
    }
    $vp = new VpToolManager(
        new RemoteToolCommandRunner(
            $probe ?? new class($vpHome) implements SshExecutor
            {
                public function __construct(private string $home) {}

                public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
                {
                    return new CommandResult(0, $this->home."/bin/vp\n", '', 1, false);
                }
            },
            app(SshKeyProvider::class),
            app(KnownHostsStore::class),
        ),
        new SemverVersionNormalizer,
    );

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
    )), $vp, app(GatewaySetupSteps::class), app(RepositoryPullRequestAccess::class));
}

function check_runner_wait(RemoteTaskCheckRunner $runner, Instance $instance, TaskCheckProcess $process): TaskCheckReading
{
    for ($attempt = 0; $attempt < 150; $attempt++) {
        $reading = $runner->read($instance, $process);
        if ($reading->state !== 'running') {
            return $reading;
        }
        usleep(100_000);
    }

    throw new RuntimeException('The check did not finish.');
}
