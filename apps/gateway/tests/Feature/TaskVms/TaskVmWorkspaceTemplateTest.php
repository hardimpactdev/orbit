<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectType;
use App\Domain\Projects\TiaBaselineFiles;
use App\Domain\Projects\TiaBaselineSource;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmQueue;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\IncusTaskVmProvider;
use App\Infrastructure\TaskVms\IncusTaskVmWorkspaceTemplateBuilder;
use App\Infrastructure\TaskVms\TaskVmBuilderShell;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplates;
use App\Jobs\TaskVms\BuildTaskVmWorkspaceTemplate;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Jobs\TaskVms\RefreshTaskVmWorkspaceTemplates;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\Support\GitRemoteRedirect;
use Tests\Support\TaskVmSettingsBuilder;
use Tests\Support\TestOrbitHome;

/**
 * A task VM host with storage volumes, disk devices, and instances, and the guests behind it. Incus commands come
 * as `sudo -n incus --project orbit-tasks …`; a command to a task VM Node is its own argv.
 */
final class TaskVmTemplateIncus implements SshExecutor
{
    /** @var list<array{string, list<string>}> */
    public array $calls = [];

    /** @var array<string, array<string, string>> */
    public array $volumes = [];

    /** @var array<string, array<string, string>> */
    public array $devices = [];

    /** @var list<string> */
    public array $instances = [];

    /** An operation that fails, such as `storage volume copy` or `setup step [build]`. */
    public ?string $fail = null;

    /** What the guest mount script prints. */
    public string $mounted = 'template';

    /** The `composer.json` of the builder's checkout, or null for none. */
    public ?string $composer = null;

    /** The exit code of an operation that does not fail with 1, such as a timed-out step (124). */
    public array $exitCodes = [];

    /** What `git status --porcelain` prints until the builder restores the checkout. */
    public ?string $dirtyUntilRestored = null;

    /** What `git status --porcelain` prints in the builder. */
    public string $status = '';

    /** The base image every VM booted, and the one `orbit-task-base` names. */
    public string $image = TVM_IMAGE;

    public string $alias = TVM_IMAGE;

    /** @var list<string> the guest scripts that ran over the task VM Node's SSH connection: `mount` or `unmount` */
    public array $guest = [];

    /** @var list<string> the setup step scripts the builder received on stdin, in order */
    public array $steps = [];

    /** The commit that the Gateway sent to the builder from its mirror. */
    public ?string $received = null;

    private ?string $input = null;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $argv = $command->arguments;
        $this->calls[] = [$connection->host, $argv];
        $this->input = $command->input;
        if (array_slice($argv, 0, 3) !== ['sudo', '-n', 'incus']) {
            // A command over the task VM Node's own SSH connection: the mount or the unmount script.
            $script = $argv[2] === TaskVmWorkspaceTemplates::UnmountScript ? 'unmount' : 'mount';
            $this->guest[] = $script;
            if ($this->fail === $script) {
                return new CommandResult(1, '', "{$script} failed\n", 1, false);
            }

            return new CommandResult(0, $script === 'mount' ? $this->mounted : '', '', 1, false);
        }
        if ($argv[3] === 'query') {
            // The alias move: `incus query -X PATCH --data {"target": …} -- /1.0/images/aliases/…`.
            return $this->answer('query', function () use ($argv): void {
                $this->alias = json_decode($argv[7], true)['target'];
            });
        }
        $operation = array_slice($argv, 5);

        return match (true) {
            array_slice($operation, 0, 3) === ['storage', 'volume', 'list'] => $this->answer('storage volume list', fn (): string => json_encode(array_map(
                static fn (string $name, array $config): array => ['name' => $name, 'type' => 'custom', 'config' => $config],
                array_keys($this->volumes),
                $this->volumes,
            ) + [count($this->volumes) => ['name' => 'tvm-1', 'type' => 'virtual-machine', 'config' => []]])),
            // Incus copies the volume's config with it.
            array_slice($operation, 0, 3) === ['storage', 'volume', 'copy'] => $this->answer('storage volume copy', function () use ($operation): void {
                $this->volumes[explode('/', $operation[5])[1]] = $this->volumes[explode('/', $operation[4])[1]] ?? [];
            }),
            array_slice($operation, 0, 3) === ['storage', 'volume', 'create'] => $this->answer('storage volume create', function () use ($operation): void {
                $this->volumes[$operation[6]] = [];
            }),
            array_slice($operation, 0, 3) === ['storage', 'volume', 'set'] => $this->answer('storage volume set', function () use ($operation): void {
                foreach (array_slice($operation, 6) as $pair) {
                    [$key, $value] = explode('=', $pair, 2);
                    $this->volumes[$operation[5]][$key] = $value;
                }
            }),
            array_slice($operation, 0, 3) === ['storage', 'volume', 'delete'] => $this->answer('storage volume delete', function () use ($operation): void {
                unset($this->volumes[end($operation)]);
            }),
            array_slice($operation, 0, 3) === ['config', 'device', 'add'] => $this->answer('config device add', function () use ($operation): void {
                if (isset($this->devices[$operation[4]][$operation[5]])) {
                    throw new RuntimeException('exists');
                }
                $this->devices[$operation[4]][$operation[5]] = substr($operation[8], strlen('source='));
            }),
            array_slice($operation, 0, 3) === ['config', 'device', 'get'] => $this->answer('config device get', fn (): string => ($this->devices[$operation[4]][$operation[5]] ?? '')."\n"),
            array_slice($operation, 0, 3) === ['config', 'device', 'remove'] => $this->answer('config device remove', function () use ($operation): void {
                unset($this->devices[$operation[4]][$operation[5]]);
            }),
            array_slice($operation, 0, 2) === ['config', 'get'] => $this->answer('config get', fn (): string => $this->image."\n"),
            array_slice($operation, 0, 2) === ['image', 'list'] => $this->answer('image list', fn (): string => json_encode([['fingerprint' => $this->alias, 'aliases' => [['name' => TaskVmHost::BaseImage]]]])),
            $operation[0] === 'launch' => $this->answer('launch', function () use ($operation): void {
                $this->instances[] = end($operation);
            }),
            $operation[0] === 'project' => $this->answer('project show', static fn (): string => "name: orbit-tasks\n"),
            $operation[0] === 'list' && in_array('csv', $operation, true) => $this->answer('list', fn (): string => implode('', array_map(static fn (string $name): string => $name."\n", $this->instances))),
            $operation[0] === 'list' => $this->answer('list', fn (): string => json_encode(array_map(fn (string $name): array => ['name' => $name, 'config' => ['volatile.base_image' => $this->image]], $this->instances))),
            $operation[0] === 'delete' => $this->answer('delete', function () use ($operation): void {
                $this->instances = array_values(array_diff($this->instances, [end($operation)]));
                unset($this->devices[end($operation)]);
            }),
            $operation[0] === 'exec' => $this->exec(array_slice($operation, array_search('--', $operation, true) + 2)),
            default => throw new RuntimeException('Unexpected incus command: '.implode(' ', $argv)),
        };
    }

    /** @return list<list<string>> the Incus operations after `incus --project orbit-tasks` */
    public function operations(): array
    {
        return array_values(array_map(
            static fn (array $call): array => array_slice($call[1], 5),
            array_filter($this->calls, static fn (array $call): bool => array_slice($call[1], 0, 3) === ['sudo', '-n', 'incus']),
        ));
    }

    /** @param  list<string>  $command */
    private function exec(array $command): CommandResult
    {
        if (($command[0] === 'timeout' ? $command[2] : $command[0]) === 'cloud-init') {
            return new CommandResult(0, '{"status":"done","errors":[]}', '', 1, false);
        }
        $user = $command[0] === 'runuser' ? array_slice($command, 4) : $command;

        return match (true) {
            // The step script arrives on stdin. Its command follows the line that sets VP_HOME.
            $user[0] === 'timeout' => $this->answer('setup step ['.$this->step().']'),
            $user[0] === 'git' => $this->answer('git status', fn (): string => $this->dirtyUntilRestored ?? $this->status),
            $user[0] === 'sh' && str_contains($user[2], 'git clean') => $this->answer('git restore', function (): void {
                $this->dirtyUntilRestored = null;
            }),
            $user[0] === 'sh' && str_contains($user[2], 'composer.json') => $this->answer('composer.json', fn (): string => $this->composer ?? 'absent'),
            $user[0] === 'sh' && str_contains($user[2], 'id -u') => $this->answer('id', static fn (): string => '1000:1000'),
            // The Gateway sends the default branch from its mirror, and the checkout prints the commit it sent.
            $user[0] === 'bash' && str_contains($user[2] ?? '', 'index-pack') => $this->answer('exec git receive', function () use ($user): void {
                $this->received = $user[7] ?? null;
            }),
            $user[0] === 'bash' && str_contains($user[2] ?? '', 'checkout --quiet --force -B') => $this->answer('clone', fn (): string => $this->received."\n"),
            default => $this->answer($user[0] === 'bash' && str_contains($user[2], 'mkfs.ext4') ? 'format' : 'exec '.$user[0]),
        };
    }

    private function step(): string
    {
        $this->steps[] = (string) $this->input;

        return substr((string) $this->input, strpos((string) $this->input, "\n") + 1);
    }

    private function answer(string $operation, ?Closure $effect = null): CommandResult
    {
        if ($this->fail === $operation || isset($this->exitCodes[$operation])) {
            return new CommandResult($this->exitCodes[$operation] ?? 1, '', "Error: {$operation} failed\n", 1, false);
        }
        try {
            $output = $effect instanceof Closure ? $effect() : null;
        } catch (RuntimeException $exception) {
            return new CommandResult(1, '', "Error: {$exception->getMessage()}\n", 1, false);
        }

        return new CommandResult(0, is_string($output) ? $output : '', '', 1, false);
    }
}

const REPO = 'https://github.com/acme/mealou.git';

const TVM_IMAGE = 'c962685c88c2aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const TVM_NEW_IMAGE = '2d91037503adbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/**
 * The config of a ready template of the Project's repository, built on `$image` by a user with uid and gid 1000.
 *
 * @return array<string, string>
 */
function tvm_ready(string $repository = REPO, string $image = TVM_IMAGE): array
{
    return ['user.orbit.ready' => 'true', 'user.orbit.repository' => $repository, 'user.orbit.image' => $image, 'user.orbit.owner' => '1000:1000', 'user.orbit.layout' => 'workspace'];
}

function tvm_template_vm(Task $group, string $name = 'tvm-1'): TaskVm
{
    $node = Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.130', 'wireguard_ip' => '10.44.0.130', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:vm',
    ]);

    return TaskVm::query()->create([
        'group_id' => $group->id, 'host_node_id' => test()->hostNode->id, 'node_id' => $node->id, 'provider' => 'incus', 'name' => $name,
        'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.130', 'pi_token' => str_repeat('p', 64),
    ]);
}

/**
 * `$count` more `vm` Projects. Every fifth has no default branch, and the first `$active` have a group from today.
 *
 * @return list<Project>
 */
function tvm_projects(int $count, int $active): array
{
    $projects = [];
    for ($i = 1; $i <= $count; $i++) {
        $project = Project::query()->create([
            'name' => "App {$i}", 'slug' => "app-{$i}", 'repository_url' => "https://github.com/acme/app-{$i}.git",
            'default_branch' => $i % 5 === 0 ? null : 'main', 'task_compute' => TaskCompute::Vm,
        ]);
        if ($i <= $active) {
            Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => TaskGroupStatus::Completed, 'task_compute' => TaskCompute::Vm]);
        }
        $projects[] = $project;
    }

    return $projects;
}

/**
 * Points every `https://github.com/` repository of these tests at one local repository with a `main` branch, so a
 * template build fetches into the Gateway's mirror without the network.
 */
function tvm_template_origins(): void
{
    $root = TestOrbitHome::scratch('tvm-template-origins');
    (new Process(['git', 'init', '--quiet', '-b', 'main', $root.'/work']))->mustRun();
    (new Process(['git', '-C', $root.'/work', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'Start']))->mustRun();
    (new Process(['git', 'clone', '--quiet', '--bare', $root.'/work', $root.'/origin.git']))->mustRun();
    $names = ['acme/mealou', 'acme/dlf', 'nckrtl/orbit', ...array_map(static fn (int $i): string => "acme/app-{$i}", range(1, 25))];
    foreach ($names as $name) {
        @mkdir(dirname($root.'/github/'.$name), 0700, true);
        symlink($root.'/origin.git', $root.'/github/'.$name.'.git');
    }
    GitRemoteRedirect::global($root.'/github/', 'https://github.com/');
}

afterEach(function (): void {
    GitRemoteRedirect::clear();
    TestOrbitHome::clearScratch();
});

beforeEach(function (): void {
    Sleep::fake();
    tvm_template_origins();
    $this->hostNode = Node::query()->create([
        'name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'nckrtl', 'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $this->host = new TaskVmHost($this->hostNode->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 4, 2, '4GiB', '40GiB');
    TaskVmSettingsBuilder::bind(['hosts' => [$this->host]]);
    $this->project = Project::query()->create([
        'name' => 'Mealou', 'slug' => 'mealou', 'repository_url' => 'https://github.com/acme/mealou.git', 'default_branch' => 'main', 'task_compute' => TaskCompute::Vm,
    ]);
    $this->group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => TaskGroupStatus::Reserved, 'task_compute' => TaskCompute::Vm]);
    $this->incus = new TaskVmTemplateIncus;
    app()->instance(SshExecutor::class, $this->incus);
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id_ed25519';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeGatewayPublicKeyMaterial0123456789abcd orbit@gateway';
        }
    });
    app()->instance(KnownHostsStore::class, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/keys/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    });
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
});

describe('claim', function (): void {
    it('clones the newest ready template of the Project, attaches it, and mounts it at the apps directory', function (): void {
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = [
            'tvm-template-'.$this->project->id.'-20261010040000' => tvm_ready(),
            'tvm-template-'.$this->project->id.'-20261011040000' => tvm_ready(),
            'tvm-template-'.$this->project->id.'-20261011050000' => [],
            'tvm-template-999-20261012040000' => tvm_ready(),
        ];

        $template = app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou');

        $mount = collect($this->incus->calls)->first(static fn (array $call): bool => $call[0] === '10.44.0.130');
        expect($template)->toBe('/home/orbit/apps/mealou/workspace')
            ->and($this->incus->operations())->toContain(
                ['storage', 'volume', 'copy', '--', 'orbit-tasks/tvm-template-'.$this->project->id.'-20261011040000', 'orbit-tasks/tvm-1-workspace'],
                ['config', 'device', 'add', '--', 'tvm-1', 'workspace', 'disk', 'pool=orbit-tasks', 'source=tvm-1-workspace'],
            )
            ->and(array_slice($mount[1], 3))->toBe(['--', 'workspace', '/home/orbit/apps/mealou', '1000:1000', 'workspace', '.orbit-cache', 'ms-playwright', 'composer'])
            ->and($mount[1][2])->toContain('/etc/fstab')->toContain('x-systemd.before=multi-user.target');
    });

    it('takes no template of an earlier repository, and asks for a build', function (): void {
        Queue::fake();
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261011040000' => tvm_ready('https://github.com/old/mealou.git')];

        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBeNull()
            ->and(collect($this->incus->operations())->contains(static fn (array $operation): bool => ($operation[2] ?? null) === 'copy'))->toBeFalse();
        Queue::assertPushed(BuildTaskVmWorkspaceTemplate::class);
    });

    it('writes the fstab line only after the mount and the owner checks', function (): void {
        $script = TaskVmWorkspaceTemplates::MountScript;

        expect(strpos($script, '"$(id -u):$(id -g)" != "$owner"'))->toBeLessThan(strpos($script, 'mount -o noatime'))
            ->and(strpos($script, 'mount -o noatime'))->toBeLessThan(strpos($script, '/etc/fstab'))
            ->and(strpos($script, 'findmnt'))->toBeLessThan(strpos($script, '/etc/fstab'))
            ->and(strrpos($script, "stat -c '%u:%g' -- \"\$directory/\$template\""))->toBeLessThan(strpos($script, '/etc/fstab'))
            ->and(strpos($script, '/etc/fstab'))->toBeLessThan(strpos($script, 'ln -sfn'));
    });

    it('takes no template built on another base image: it asks for a build when the VM runs the current image', function (): void {
        Queue::fake();
        Log::spy();
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261012040223' => tvm_ready()];
        $this->incus->image = $this->incus->alias = TVM_NEW_IMAGE;

        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBeNull()
            ->and(collect($this->incus->operations())->contains(static fn (array $operation): bool => ($operation[2] ?? null) === 'copy'))->toBeFalse()
            ->and($this->incus->guest)->toBe([]);
        Queue::assertPushed(BuildTaskVmWorkspaceTemplate::class);
        Log::shouldHaveReceived('warning')->with('Task VM workspace template was built on another base image; it starts from a fresh clone, and a new build is queued.', Mockery::any());
    });

    it('asks for no build for a VM from an older base image than the template', function (): void {
        Queue::fake();
        Log::spy();
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261012090000' => tvm_ready(image: TVM_NEW_IMAGE)];
        $this->incus->alias = TVM_NEW_IMAGE;

        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBeNull();
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('warning')->with('Task VM runs an older base image than the workspace templates; it starts from a fresh clone.', Mockery::any());
    });

    it('takes the template built on the VM image over a newer one built on another image', function (): void {
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = [
            'tvm-template-'.$this->project->id.'-20261012040000' => tvm_ready(),
            'tvm-template-'.$this->project->id.'-20261012090000' => tvm_ready(image: TVM_NEW_IMAGE),
        ];

        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBe('/home/orbit/apps/mealou/workspace')
            ->and($this->incus->operations())->toContain(['storage', 'volume', 'copy', '--', 'orbit-tasks/tvm-template-'.$this->project->id.'-20261012040000', 'orbit-tasks/tvm-1-workspace']);
    });

    it('finds the clone, the disk, and the mount in place when the claim runs again', function (): void {
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261011040000' => tvm_ready()];
        app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou');
        $this->incus->calls = [];

        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBe('/home/orbit/apps/mealou/workspace')
            ->and(collect($this->incus->operations())->contains(static fn (array $operation): bool => $operation[2] === 'copy'))->toBeFalse()
            ->and($this->incus->operations())->toContain(['config', 'device', 'get', '--', 'tvm-1', 'workspace', 'source']);
    });

    it('starts from a fresh clone without a template and asks for a build, unless the last build failed', function (): void {
        Queue::fake();
        Log::spy();
        $vm = tvm_template_vm($this->group);

        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBeNull();
        Queue::assertPushed(BuildTaskVmWorkspaceTemplate::class, fn (BuildTaskVmWorkspaceTemplate $job): bool => $job->hostNodeId === $this->hostNode->id && $job->projectId === $this->project->id);
        Log::shouldHaveReceived('warning')->with('Task VM workspace has no template; it starts from a fresh clone.', Mockery::any());

        Queue::fake();
        Cache::put(TaskVmWorkspaceTemplates::backoffKey($this->hostNode->id, $this->project->id), 1, 60);
        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBeNull();
        Queue::assertNothingPushed();
    });

    it('detaches a volume whose mount failed, so the fresh clone lands on the VM disk', function (): void {
        Log::spy();
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261011040000' => tvm_ready()];
        $this->incus->fail = 'mount';

        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBeNull()
            ->and($this->incus->guest)->toBe(['mount', 'unmount'])
            ->and($this->incus->devices['tvm-1'] ?? [])->toBe([])
            ->and(array_keys($this->incus->volumes))->toBe(['tvm-template-'.$this->project->id.'-20261011040000'])
            ->and($this->incus->operations())->toContain(['config', 'device', 'remove', '--', 'tvm-1', 'workspace']);
        Log::shouldHaveReceived('warning')->with('Task VM workspace template could not be attached; the workspace starts from a fresh clone.', Mockery::any());
    });

    it('stops the claim when a failed mount cannot be undone, so no clone lands on the volume', function (): void {
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261011040000' => tvm_ready()];
        $this->incus->fail = 'mount';
        $this->incus->guest = [];
        // The mount fails, and so does the unmount after it.
        $incus = $this->incus;
        app()->instance(SshExecutor::class, new class($incus) implements SshExecutor
        {
            public function __construct(private readonly TaskVmTemplateIncus $incus) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                if ($command->arguments[2] === TaskVmWorkspaceTemplates::UnmountScript) {
                    $this->incus->guest[] = 'unmount';

                    return new CommandResult(32, '', "umount: target is busy\n", 1, false);
                }

                return $this->incus->execute($connection, $command);
            }
        });

        expect(fn () => app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.workspace_template_detach_failed'));
        expect($this->incus->guest)->toBe(['mount', 'unmount'])
            ->and($this->incus->volumes)->toHaveKey('tvm-1-workspace');
    });

    it('starts from a fresh clone and logs a warning when a step fails or the volume holds no template', function (string $failure): void {
        Log::spy();
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261011040000' => tvm_ready()];
        $failure === 'empty' ? $this->incus->mounted = '' : $this->incus->fail = $failure;

        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBeNull();
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_starts_with($message, 'Task VM workspace'));
    })->with(['storage volume copy', 'config device add', 'mount', 'empty']);
});

describe('volumes', function (): void {
    it('deletes the clone with the VM, and counts an absent clone as deleted', function (): void {
        $vm = tvm_template_vm($this->group);
        $this->incus->instances = ['tvm-1'];
        $this->incus->volumes = ['tvm-1-workspace' => []];

        app(IncusTaskVmProvider::class)->destroy($vm);
        app(IncusTaskVmProvider::class)->destroy($vm);

        expect($this->incus->instances)->toBe([])->and($this->incus->volumes)->toBe([]);
    });

    it('sweeps clones whose task VM has no live row', function (): void {
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = [
            'tvm-1-workspace' => [], 'tvm-77-workspace' => [], 'tvm-template-'.$this->project->id.'-20261011040000' => tvm_ready(), 'other' => [],
        ];

        app()->call([new SweepTaskVmHosts, 'handle']);

        expect(array_keys($this->incus->volumes))->toBe(['tvm-1-workspace', 'tvm-template-'.$this->project->id.'-20261011040000', 'other'])
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Ready);
    });

    it('deletes the templates of Projects that no longer run task VMs and queues a build for an active one without a current template', function (): void {
        Queue::fake();
        $shared = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
        $this->incus->volumes = [
            'tvm-template-'.$this->project->id.'-20261011040000' => tvm_ready(image: TVM_NEW_IMAGE),
            'tvm-template-'.$shared->id.'-20261011040000' => tvm_ready(),
            'tvm-template-'.$shared->id.'-20261011050000' => [],
        ];

        app()->call([new RefreshTaskVmWorkspaceTemplates, 'handle']);

        expect(array_keys($this->incus->volumes))->toBe(['tvm-template-'.$shared->id.'-20261011050000']);
        Queue::assertPushed(BuildTaskVmWorkspaceTemplate::class, 1);
        Queue::assertPushed(BuildTaskVmWorkspaceTemplate::class, fn (BuildTaskVmWorkspaceTemplate $job): bool => $job->projectId === $this->project->id);
    });

    it('serves the Orbit Project like every Project whose groups run on task VMs', function (): void {
        $orbit = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/nckrtl/orbit.git', 'default_branch' => 'main', 'task_compute' => TaskCompute::Vm]);

        expect(TaskVmWorkspaceTemplates::serves($orbit))->toBeTrue();

        $orbit->update(['task_compute' => TaskCompute::Shared]);
        expect(TaskVmWorkspaceTemplates::serves($orbit))->toBeFalse();
    });

    it('has no clock of its own: whoever builds or syncs a base image queues the template refresh, not the promote', function (): void {
        Artisan::call('schedule:list');
        $events = collect(app(Schedule::class)->events())->filter(static fn ($event): bool => $event instanceof CallbackEvent);

        expect($events->contains(static fn (CallbackEvent $event): bool => $event->description === RefreshTaskVmWorkspaceTemplates::class))->toBeFalse()
            ->and($events->contains(static fn (CallbackEvent $event): bool => $event->description === RefreshTaskVmBaseImages::class && $event->expression === '0 3 * * *'))->toBeTrue();

        Queue::fake();
        $this->incus->alias = TVM_NEW_IMAGE;
        app(IncusTaskVmBaseImage::class)->promote($this->host, $this->hostNode, TVM_IMAGE);
        Queue::assertNotPushed(RefreshTaskVmWorkspaceTemplates::class);
    });
});

describe('scale', function (): void {
    it('after a promote, deletes the old templates and builds ahead only for active Projects, on the template queue', function (): void {
        Log::spy();
        $shark = Node::query()->create([
            'name' => 'shark', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
            'public_ssh_host' => '10.44.0.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'nckrtl', 'ssh_host_fingerprint' => 'SHA256:shark',
        ]);
        $sharkHost = new TaskVmHost($shark->id, 'orbit-tasks', 'orbittask0', '10.251.78.0/24', 4, 2, '4GiB', '40GiB');
        TaskVmSettingsBuilder::bind(['hosts' => [$this->host, $sharkHost]]);
        // Mealou is active through the group of the setup; its old group does not count.
        $projects = tvm_projects(25, active: 6);
        Task::topLevel()->create(['project_id' => $projects[10]->id, 'title' => 'Old', 'brief' => 'Old', 'status' => TaskGroupStatus::Completed, 'task_compute' => TaskCompute::Vm])
            ->forceFill(['created_at' => now()->subDays(TaskVmWorkspaceTemplates::ActiveDays + 1)])->save();
        $this->incus->volumes = [
            'tvm-template-'.$this->project->id.'-20261011040000' => tvm_ready(),
            'tvm-template-'.$projects[0]->id.'-20261011040000' => tvm_ready($projects[0]->repository_url),
            'tvm-template-'.$projects[1]->id.'-20261011040000' => tvm_ready($projects[1]->repository_url, TVM_NEW_IMAGE),
            'tvm-template-'.$projects[1]->id.'-20261011050000' => tvm_ready($projects[1]->repository_url, TVM_NEW_IMAGE),
            'tvm-template-'.$projects[2]->id.'-20261011040000' => tvm_ready('https://github.com/old/app-3.git', TVM_NEW_IMAGE),
            'tvm-template-'.$projects[3]->id.'-20261011060000' => [],
        ];

        app(IncusTaskVmBaseImage::class)->promote($this->host, $this->hostNode, TVM_NEW_IMAGE);
        app(IncusTaskVmBaseImage::class)->promote($sharkHost, $shark, TVM_NEW_IMAGE);
        // The build and sync queue one refresh when every host is done, as the nightly refresh does.
        RefreshTaskVmWorkspaceTemplates::dispatch();

        // One refresh, on the template queue, and nothing on the task VM queue.
        expect(DB::table('jobs')->pluck('queue')->all())->toBe([TaskVmQueue::Templates->value]);

        app()->call([new RefreshTaskVmWorkspaceTemplates, 'handle']);

        // One template per Project, none from the old image or an old repository; an unfinished build stays.
        expect(array_keys($this->incus->volumes))->toBe([
            'tvm-template-'.$projects[1]->id.'-20261011050000',
            'tvm-template-'.$projects[3]->id.'-20261011060000',
        ]);
        // Mealou and app-1..app-6 are active; app-5 has no default branch, and app-2 is current. 5 Projects on 2 hosts.
        $builds = DB::table('jobs')->where('payload', 'like', '%BuildTaskVmWorkspaceTemplate%')->get();
        $built = $builds->map(static fn (object $job): array => [unserialize(json_decode($job->payload, true)['data']['command'])->hostNodeId, unserialize(json_decode($job->payload, true)['data']['command'])->projectId])->all();
        $expected = [];
        foreach ([$this->hostNode->id, $shark->id] as $host) {
            foreach ([$this->project, ...array_slice($projects, 0, 6)] as $project) {
                // app-2 already has a current template, so it gets no build.
                if ($project->default_branch !== null && $project->id !== $projects[1]->id) {
                    $expected[] = [$host, $project->id];
                }
            }
        }
        expect($built)->toBe($expected)
            ->and($builds->pluck('queue')->unique()->all())->toBe([TaskVmQueue::Templates->value])
            ->and(DB::table('jobs')->where('queue', 'task-vms')->count())->toBe(0);
        Log::shouldHaveReceived('warning')->with('Task VM workspace template cannot be built; workspaces of the Project start from a fresh clone.', Mockery::on(
            static fn (array $context): bool => $context['reason'] === 'the Project has no valid default branch',
        ))->times(5);
    });

    it('keeps task VM jobs ahead of template builds and base image refreshes: each queue has its own worker', function (): void {
        $this->travelTo(now()->setTime(4, 0, 10));
        tvm_projects(25, active: 25);
        app()->call([new RefreshTaskVmWorkspaceTemplates, 'handle']);
        DestroyTaskVm::dispatch(tvm_template_vm($this->group)->id);

        Artisan::call('schedule:list');
        $events = app(Schedule::class)->events();
        $workers = collect($events)->filter(static fn (Event $event): bool => str_contains((string) $event->command, 'queue:work'))->values();
        [$vms, $images, $templates] = [$workers[0], $workers[1], $workers[2]];

        expect(DB::table('jobs')->where('queue', TaskVmQueue::Templates->value)->count())->toBe(21)
            ->and($vms->command)->toEndWith("'artisan' queue:work task-vms --queue=task-vms --stop-when-empty --max-time=120 --timeout=1500")
            ->and($images->command)->toEndWith("'artisan' queue:work task-vms --queue=task-vm-images --stop-when-empty --max-time=120 --timeout=1700")
            ->and($templates->command)->toEndWith("'artisan' queue:work task-vms --queue=task-vm-templates --stop-when-empty --max-time=120 --timeout=1700")
            ->and([$images->repeatSeconds, $images->withoutOverlapping, $images->expiresAt, $images->runInBackground])->toBe([1, true, 32, false])
            ->and($templates)->toBe(end($events))
            ->and([$templates->repeatSeconds, $templates->withoutOverlapping, $templates->expiresAt, $templates->runInBackground])->toBe([1, true, 32, false])
            // The task VM worker takes the destroy at once, behind no build.
            ->and($vms->filtersPass(app()))->toBeTrue()
            // The template worker starts only in the last two seconds of a minute, so the run it holds ends soon.
            ->and($templates->filtersPass(app()))->toBeFalse();

        $this->travelTo(now()->setTime(4, 0, 58));
        expect($templates->filtersPass(app()))->toBeTrue();

        // A base image refresh starts the image worker only in seconds 56 and 57, and never the template worker.
        RefreshTaskVmBaseImages::dispatch();
        expect($images->filtersPass(app()))->toBeFalse();
        $this->travelTo(now()->setTime(4, 1, 56));
        expect([$images->filtersPass(app()), $templates->filtersPass(app())])->toBe([true, false]);

        DB::table('jobs')->where('queue', 'task-vms')->delete();
        expect($vms->filtersPass(app()))->toBeFalse();
    });

    it('starts a claim from a fresh clone while its template builds, and queues no second build', function (): void {
        Log::spy();
        BuildTaskVmWorkspaceTemplate::dispatch($this->hostNode->id, $this->project->id);
        // The running build holds the host and has an unfinished volume; the old image's template is gone.
        Cache::lock('task-vms:workspace-template:'.$this->hostNode->id, 60)->get();
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261012040000' => []];
        $vm = tvm_template_vm($this->group);

        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBeNull()
            ->and(collect($this->incus->operations())->contains(static fn (array $operation): bool => ($operation[2] ?? null) === 'copy'))->toBeFalse()
            ->and(DB::table('jobs')->count())->toBe(1);
        Log::shouldHaveReceived('warning')->with('Task VM workspace has no template; it starts from a fresh clone.', Mockery::any());
    });

    it('skips a Project without a default branch loudly, with no build, retry, or backoff', function (): void {
        Log::spy();
        $this->project->update(['default_branch' => null]);
        $vm = tvm_template_vm($this->group);

        // A claim asks for no build.
        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBeNull()
            ->and(DB::table('jobs')->count())->toBe(0);

        // A queued build launches nothing, and neither fails nor waits.
        $this->incus->calls = [];
        $job = (new BuildTaskVmWorkspaceTemplate($this->hostNode->id, $this->project->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->assertNotFailed();
        $job->assertNotReleased();
        expect($this->incus->calls)->toBe([])
            ->and(Cache::has(TaskVmWorkspaceTemplates::backoffKey($this->hostNode->id, $this->project->id)))->toBeFalse();
        Log::shouldHaveReceived('warning')->with('Task VM workspace template cannot be built; workspaces of the Project start from a fresh clone.', Mockery::on(
            static fn (array $context): bool => $context['reason'] === 'the Project has no valid default branch',
        ))->twice();

        expect(Artisan::call('task-vms:build-workspace-template', ['node' => 'beast', 'project' => 'mealou']))->toBe(1)
            ->and(Artisan::output())->toContain('cannot have a workspace template: the Project has no valid default branch');
    });
});

describe('build', function (): void {
    beforeEach(function (): void {
        foreach (['composer-install' => 'composer install --no-interaction', 'build-assets' => 'vp build'] as $name => $command) {
            ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => LifecyclePhase::Setup->value, 'name' => $name, 'command' => $command, 'timeout_seconds' => 120, 'position' => $name === 'build-assets' ? 2 : 1]);
        }
    });

    it('builds the template in a builder VM, marks it ready, and deletes the older ones', function (): void {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(4, 0));
        $old = 'tvm-template-'.$this->project->id.'-20261011040000';
        $this->incus->volumes = [$old => tvm_ready(), 'tvm-template-'.$this->project->id.'-20261011043000' => []];
        $this->incus->instances = ['tvm-template-20261011043000', 'tvm-5'];
        $stages = [];

        $volume = app(IncusTaskVmWorkspaceTemplateBuilder::class)->build($this->host, $this->hostNode, $this->project, function (string $stage) use (&$stages): void {
            $stages[] = $stage;
        });

        // The builder got the default branch from the Gateway's mirror, as a pack, never with a token.
        expect($this->incus->received)->toMatch('/\A[0-9a-f]{40}\z/');
        expect($volume)->toBe('tvm-template-'.$this->project->id.'-20261012040000')
            ->and($stages)->toBe(['render', 'sweep', 'launch', 'cloud-init', 'identity', 'volume', 'mount', 'clone', 'php', 'setup:composer-install', 'setup:build-assets', 'warm-check', 'verify', 'detach', 'publish', 'delete-builder', 'prune'])
            ->and($this->incus->volumes)->toBe([$volume => [
                'user.orbit.project' => (string) $this->project->id, 'user.orbit.commit' => $this->incus->received, 'user.orbit.repository' => REPO,
                'user.orbit.image' => TVM_IMAGE, 'user.orbit.owner' => '1000:1000', 'user.orbit.layout' => 'workspace', 'user.orbit.ready' => 'true',
            ]])
            ->and($this->incus->instances)->toBe(['tvm-5'])
            ->and($this->incus->operations())->toContain(
                ['launch', '--vm', '--config', 'limits.cpu=2', '--config', 'limits.memory=4GiB', '--device', 'root,size=40GiB', '--device', 'eth0,network=orbittask0', '--device', 'eth0,security.port_isolation=true', '--', TaskVmHost::BaseImage, 'tvm-template-20261012040000'],
                ['storage', 'volume', 'create', '--type=block', '--', 'orbit-tasks', $volume, 'size=40GiB'],
                ['exec', '--cwd', '/home/orbit/apps/mealou/workspace', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit', '--', 'tvm-template-20261012040000', 'runuser', '-u', 'orbit', '--', 'timeout', '120', 'bash', '-c', 'script=$(mktemp) && trap \'rm -f -- "$script"\' EXIT && cat > "$script" && bash -l "$script" < /dev/null'],
            )
            ->and($this->incus->steps)->toBe([
                'if vp=$(command -v vp); then export VP_HOME="$(dirname "$(dirname "$vp")")"; fi'."\n".'composer install --no-interaction',
                'if vp=$(command -v vp); then export VP_HOME="$(dirname "$(dirname "$vp")")"; fi'."\n".'vp build',
            ])
            ->and(Cache::lock('task-vms:workspace-template:'.$this->hostNode->id, 10)->get())->toBeTrue();
    });

    it('stops what the managed user left running in the builder before it unmounts the volume', function (): void {
        app(IncusTaskVmWorkspaceTemplateBuilder::class)->build($this->host, $this->hostNode, $this->project);

        $unmount = collect($this->incus->operations())->first(static fn (array $operation): bool => str_contains(implode(' ', $operation), 'pkill -KILL'));
        expect(array_slice($unmount, -2))->toBe(['/home/orbit/apps/mealou', 'orbit'])
            ->and((string) $unmount[count($unmount) - 4])->toContain('pkill -KILL -u "$user"')
            ->and(strpos((string) $unmount[count($unmount) - 4], 'pkill'))->toBeLessThan(strpos((string) $unmount[count($unmount) - 4], 'umount --'));
    });

    it('deletes its builder and volume and keeps the current template when a step fails', function (string $failure, string $stage): void {
        $current = 'tvm-template-'.$this->project->id.'-20261011040000';
        $this->incus->volumes = [$current => tvm_ready()];
        $failure === 'dirty' ? $this->incus->status = " M composer.lock\n" : $this->incus->fail = $failure;

        expect(fn () => app(IncusTaskVmWorkspaceTemplateBuilder::class)->build($this->host, $this->hostNode, $this->project))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.workspace_template_failed')->and($exception->getMessage())->toContain("stage [{$stage}]"));

        expect($this->incus->volumes)->toBe([$current => tvm_ready()])
            ->and($this->incus->instances)->toBe([])
            ->and(Cache::lock('task-vms:workspace-template:'.$this->hostNode->id, 10)->get())->toBeTrue();
    })->with([
        'a setup step' => ['setup step [vp build]', 'setup:build-assets'],
        'the clone' => ['clone', 'clone'],
        'a dirty tree' => ['dirty', 'verify'],
    ]);

    it('keeps a published template when a cleanup stage after it fails', function (): void {
        Log::spy();
        $this->incus->fail = 'delete';

        $volume = app(IncusTaskVmWorkspaceTemplateBuilder::class)->build($this->host, $this->hostNode, $this->project);

        expect($this->incus->volumes)->toHaveKey($volume)
            ->and($this->incus->volumes[$volume]['user.orbit.ready'])->toBe('true');
        Log::shouldHaveReceived('warning')->with('Task VM workspace template is ready, but a cleanup stage failed; the next build retries it.', Mockery::any());
    });

    it('runs the TIA baseline step as the check does, with VP_HOME, within the build budget', function (): void {
        ProjectLifecycleStep::query()->where('project_id', $this->project->id)->where('name', 'composer-install')->update(['command' => LifecycleStep::RestoreTiaBaseline]);
        $files = Closure::bind(static fn (): TiaBaselineFiles => new TiaBaselineFiles(['tia.json' => '{}']), null, TiaBaselineFiles::class)();
        app()->instance(TiaBaselineSource::class, new class($files) implements TiaBaselineSource
        {
            public function __construct(private readonly TiaBaselineFiles $files) {}

            public function fetch(Project $project): TiaBaselineFiles
            {
                return $this->files;
            }
        });

        app(IncusTaskVmWorkspaceTemplateBuilder::class)->build($this->host, $this->hostNode, $this->project);

        $steps = collect($this->incus->operations())->filter(static fn (array $operation): bool => in_array('timeout', $operation, true))->values();
        expect($this->incus->steps[0])->toContain('export VP_HOME=')->toContain('python3 -c')->not->toContain(LifecycleStep::RestoreTiaBaseline)
            ->and((int) $steps[1][array_search('timeout', $steps[1], true) + 1])->toBeLessThanOrEqual(120);
    });

    it('installs no PHP for a monorepo whose task workspaces have no Route, as its Instance does', function (bool $routed): void {
        $this->project->update(['type' => ProjectType::Monorepo, 'task_workspace_routed' => $routed]);
        $this->incus->composer = '{"require":{"php":"^8.5"}}';
        $build = fn (): string => app(IncusTaskVmWorkspaceTemplateBuilder::class)->build($this->host, $this->hostNode, $this->project);
        $reads = fn (): int => collect($this->incus->operations())->filter(static fn (array $operation): bool => str_contains(implode(' ', $operation), 'composer.json'))->count();

        if ($routed) {
            expect($build)->toThrow(fn (TaskVmException $exception) => expect($exception->getMessage())->toContain('stage [php]'));
            expect($reads())->toBe(1);

            return;
        }
        expect($build())->toStartWith('tvm-template-')->and($reads())->toBe(0);
    })->with(['unrouted' => false, 'routed' => true]);

    it('warms the template with the task check once, and keeps the template when the check fails or runs out of budget', function (?int $exit, string $level, string $message): void {
        Log::spy();
        $this->project->update(['task_check' => 'composer check']);
        if ($exit !== null) {
            $this->incus->exitCodes['setup step [composer check]'] = $exit;
        }
        $stages = [];

        $volume = app(IncusTaskVmWorkspaceTemplateBuilder::class)->build($this->host, $this->hostNode, $this->project, function (string $stage) use (&$stages): void {
            $stages[] = $stage;
        });

        expect($this->incus->volumes[$volume]['user.orbit.ready'])->toBe('true')
            ->and(array_slice($stages, array_search('setup:build-assets', $stages, true), 3))->toBe(['setup:build-assets', 'warm-check', 'verify'])
            ->and(last($this->incus->steps))->toEndWith("\ncomposer check");
        $warm = collect($this->incus->operations())->first(static fn (array $operation): bool => in_array('timeout', $operation, true) && str_contains((string) end($operation), 'tail -c 2000'));
        expect(array_slice($warm, array_search('timeout', $warm, true) + 1, 2))->toBe(['-k', '10'])
            ->and((int) $warm[array_search('timeout', $warm, true) + 3])->toBeGreaterThan(1000);
        Log::shouldHaveReceived($level)->with(Mockery::on(static fn (string $text): bool => str_starts_with($text, $message)), Mockery::on(static fn (array $context): bool => $context['command'] === 'composer check' && is_float($context['seconds'])));
    })->with([
        'passes' => [null, 'info', 'Task VM workspace template warmed its caches with the task check.'],
        'fails' => [1, 'warning', 'Task VM workspace template task check failed'],
        'runs out of budget' => [124, 'warning', 'Task VM workspace template task check stopped at the build budget'],
    ]);

    it('restores what the warm-up check changed and names the stage', function (): void {
        Log::spy();
        $this->project->update(['task_check' => 'composer check']);
        $this->incus->dirtyUntilRestored = " M composer.lock\n";

        $volume = app(IncusTaskVmWorkspaceTemplateBuilder::class)->build($this->host, $this->hostNode, $this->project);

        expect($this->incus->volumes[$volume]['user.orbit.ready'])->toBe('true');
        Log::shouldHaveReceived('warning')->with('Task VM workspace template task check changed the checkout; the warm-check stage restored it.', Mockery::on(static fn (array $context): bool => $context['changes'] === 'M composer.lock'));
    });

    it('treats a template from before the fixed checkout path as not current, so it is rebuilt', function (): void {
        $old = tvm_ready();
        unset($old['user.orbit.layout']);

        expect(TaskVmWorkspaceTemplates::isCurrent(['tvm-template-'.$this->project->id.'-20261011040000' => $old], $this->project, TVM_IMAGE))->toBeFalse()
            ->and(TaskVmWorkspaceTemplates::isCurrent(['tvm-template-'.$this->project->id.'-20261011040000' => tvm_ready()], $this->project, TVM_IMAGE))->toBeTrue();
    });

    it('refuses a second build on the host while one runs', function (): void {
        Cache::lock('task-vms:workspace-template:'.$this->hostNode->id, 60)->get();

        expect(fn () => app(IncusTaskVmWorkspaceTemplateBuilder::class)->build($this->host, $this->hostNode, $this->project))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.workspace_template_running'));
    });

    it('runs Node programs in the builder as orbit through the host', function (): void {
        $shell = new TaskVmBuilderShell(app(IncusTaskVmBaseImage::class)->shell($this->host, $this->hostNode, 'tvm-template-1'));

        $shell->execute(new SshConnection('127.0.0.1', 'orbit', 22, '/k', '/h', commandTimeout: 60.0), new RemoteCommand(['sudo', '-n', 'true'], 'input'));

        expect($this->incus->calls[0][1])->toBe(['sudo', '-n', 'incus', '--project', 'orbit-tasks', 'exec', '--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit', '--', 'tvm-template-1', 'runuser', '-u', 'orbit', '--', 'sudo', '-n', 'true']);
    });
});

describe(BuildTaskVmWorkspaceTemplate::class, function (): void {
    it('logs a failed build and makes claims ask for no new build for six hours', function (): void {
        Log::spy();
        $this->incus->fail = 'launch';
        $job = new BuildTaskVmWorkspaceTemplate($this->hostNode->id, $this->project->id);

        app()->call([$job->withFakeQueueInteractions(), 'handle']);

        $job->assertFailed();
        expect(Cache::has(TaskVmWorkspaceTemplates::backoffKey($this->hostNode->id, $this->project->id)))->toBeTrue();
        Log::shouldHaveReceived('error')->with('Task VM workspace template build failed; workspaces of the Project start from a fresh clone.', Mockery::any());
    });

    it('waits a minute while another build runs on the host, and skips a Project without task VMs', function (): void {
        Cache::lock('task-vms:workspace-template:'.$this->hostNode->id, 60)->get();
        $job = new BuildTaskVmWorkspaceTemplate($this->hostNode->id, $this->project->id);

        app()->call([$job->withFakeQueueInteractions(), 'handle']);
        $job->assertReleased(60);

        $this->project->update(['task_compute' => TaskCompute::Shared]);
        $this->incus->calls = [];
        app()->call([(new BuildTaskVmWorkspaceTemplate($this->hostNode->id, $this->project->id))->withFakeQueueInteractions(), 'handle']);
        expect($this->incus->calls)->toBe([]);
    });

    it('builds through the command and prints each stage', function (): void {
        expect(Artisan::call('task-vms:build-workspace-template', ['node' => 'beast', 'project' => 'mealou']))->toBe(0)
            ->and(Artisan::output())->toContain('clone')->toContain('prune')->toContain('has the workspace template [tvm-template-'.$this->project->id.'-');

        $this->project->update(['task_compute' => TaskCompute::Shared]);
        expect(Artisan::call('task-vms:build-workspace-template', ['node' => 'beast', 'project' => 'mealou']))->toBe(1)
            ->and(Artisan::output())->toContain('does not run its groups on task VMs');
    });
});

describe('refresh order', function (): void {
    /**
     * Runs the template worker until its queue is empty, as the scheduler would. The memory limit is above the test
     * process's own use: at the default of 128 MB the worker stops after its first job in a large parallel run.
     */
    function tvm_template_worker(): void
    {
        Artisan::call('queue:work', ['connection' => 'task-vms', '--queue' => TaskVmQueue::Templates->value, '--stop-when-empty' => true, '--memory' => 4096]);
    }

    /** @return int the builder VMs that were launched */
    function tvm_builds(TaskVmTemplateIncus $incus): int
    {
        return collect($incus->operations())->filter(static fn (array $operation): bool => $operation[0] === 'launch')->count();
    }

    it('builds each active template once when a promote lands while the refresh of the first one runs', function (): void {
        // Builders boot what the alias names after the promote.
        $this->incus->image = TVM_NEW_IMAGE;
        tvm_projects(2, active: 2);
        $promoted = false;
        // The real order: the first promote and its refresh, which queues its builds; the next promote and refresh land after it.
        Queue::after(function (JobProcessed $event) use (&$promoted): void {
            if (! $promoted && $event->job->resolveName() === RefreshTaskVmWorkspaceTemplates::class) {
                $promoted = true;
                app(IncusTaskVmBaseImage::class)->promote($this->host, $this->hostNode, TVM_NEW_IMAGE);
                RefreshTaskVmWorkspaceTemplates::dispatch();
            }
        });

        app(IncusTaskVmBaseImage::class)->promote($this->host, $this->hostNode, TVM_NEW_IMAGE);
        RefreshTaskVmWorkspaceTemplates::dispatch();
        tvm_template_worker();

        // Mealou, app-1 and app-2 are built once. The second refresh finds them current and queues nothing.
        expect($promoted)->toBeTrue()
            ->and(tvm_builds($this->incus))->toBe(3)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(collect($this->incus->volumes)->filter(static fn (array $config): bool => ($config['user.orbit.image'] ?? null) === TVM_NEW_IMAGE)->count())->toBe(3);
    });

    it('skips a build when the host already has a current template', function (): void {
        Log::spy();
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261012040000' => tvm_ready()];

        app()->call([(new BuildTaskVmWorkspaceTemplate($this->hostNode->id, $this->project->id))->withFakeQueueInteractions(), 'handle']);

        expect(tvm_builds($this->incus))->toBe(0);
        Log::shouldHaveReceived('info')->with('Task VM workspace template is current; no build.', Mockery::any());

        // Another owner or image is not current.
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261012040000' => [...tvm_ready(), 'user.orbit.owner' => '1001:1001']];
        app()->call([(new BuildTaskVmWorkspaceTemplate($this->hostNode->id, $this->project->id))->withFakeQueueInteractions(), 'handle']);
        expect(tvm_builds($this->incus))->toBe(1);
    });

    it('queues no refresh at a promote, and one from the nightly base image refresh stopped at its timeout', function (): void {
        app(IncusTaskVmBaseImage::class)->promote($this->host, $this->hostNode, TVM_NEW_IMAGE);
        expect(DB::table('jobs')->count())->toBe(0);

        // A nightly job stopped at its timeout never reached its own refresh: `failed()` queues it, once.
        (new RefreshTaskVmBaseImages)->failed(null);
        (new RefreshTaskVmBaseImages)->failed(null);
        expect(DB::table('jobs')->pluck('queue')->all())->toBe([TaskVmQueue::Templates->value]);
    });
});
