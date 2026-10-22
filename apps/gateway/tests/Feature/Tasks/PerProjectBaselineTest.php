<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
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

require_once __DIR__.'/../../Support/TaskCheckRunnerFixtures.php';

function per_project_baseline_checkout(): string
{
    $checkout = test()->directory.'/'.bin2hex(random_bytes(6));
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start'], $checkout))->mustRun();

    return $checkout;
}

function per_project_baseline_runner(): RemoteTaskCheckRunner
{
    $transport = new LocalShellSshExecutor;
    $transport->environment['PATH'] = StandardLibraryPython::path((string) getenv('PATH'));

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
    )), ResolvedVp::manager(), app(GatewaySetupSteps::class), app(RepositoryPullRequestAccess::class));
}

function per_project_baseline_instance(?string $command): Instance
{
    $project = Project::query()->create([
        'name' => 'Project',
        'slug' => 'project',
        'repository_url' => 'git@github.com:acme/project.git',
        'task_check' => $command,
    ]);
    expect($project->taskCheckCommand())->toBe($command);
    $node = Node::query()->create([
        'name' => 'baseline-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.222',
        'wireguard_ip' => '10.44.0.222',
        'user' => 'orbit',
    ]);

    return test_ready_task_workspace($project, $node, ['name' => 'task-85', 'checkout_path' => per_project_baseline_checkout(), 'branch' => 'task-85']);
}

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/orbit-per-project-baseline-'.bin2hex(random_bytes(6));
    mkdir($this->directory, 0755, true);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('preserves per-project custom baseline command failure output', function (): void {
    $instance = per_project_baseline_instance('printf "custom baseline failed\\n"; exit 7');
    $runner = per_project_baseline_runner();
    $reading = check_runner_wait($runner, $instance, $runner->start($instance, command: $instance->project->taskCheckCommand()));
    expect($reading->exitCode)->toBe(7)
        ->and($reading->output)->toContain('custom baseline failed');
});

it('skips an unset per-project baseline without invoking Composer', function (): void {
    $instance = per_project_baseline_instance(null);
    $runner = per_project_baseline_runner();
    $reading = check_runner_wait($runner, $instance, $runner->start($instance, command: $instance->project->taskCheckCommand()));
    expect($reading->exitCode)->toBe(0)
        ->and($reading->output)->not->toContain('composer check');
});

it('runs deliverables when the per-project baseline command is unset', function (): void {
    $instance = per_project_baseline_instance(null);
    $runner = per_project_baseline_runner();
    $process = $runner->start($instance, command: $instance->project->taskCheckCommand(), deliverables: [
        'start' => null,
        'tests' => [],
        'commands' => [['id' => 'hello', 'command' => 'printf "deliverable ran\\n"', 'directory' => null]],
    ]);
    $reading = check_runner_wait($runner, $instance, $process);
    expect($reading->exitCode)->toBe(0)
        ->and($reading->deliverables['commands']['hello']['exit_code'] ?? null)->toBe(0)
        ->and($reading->output)->toContain('deliverable ran')->not->toContain('composer check');
});
