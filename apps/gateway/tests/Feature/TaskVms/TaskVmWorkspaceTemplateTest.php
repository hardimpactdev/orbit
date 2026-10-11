<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\TiaBaselineFiles;
use App\Domain\Projects\TiaBaselineSource;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\IncusTaskVmProvider;
use App\Infrastructure\TaskVms\IncusTaskVmWorkspaceTemplateBuilder;
use App\Infrastructure\TaskVms\TaskVmBuilderShell;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplates;
use App\Jobs\TaskVms\BuildTaskVmWorkspaceTemplate;
use App\Jobs\TaskVms\RefreshTaskVmWorkspaceTemplates;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

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

    /** What `git status --porcelain` prints in the builder. */
    public string $status = '';

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $argv = $command->arguments;
        $this->calls[] = [$connection->host, $argv];
        if (array_slice($argv, 0, 3) !== ['sudo', '-n', 'incus']) {
            // A command over the task VM Node's own SSH connection: the mount script.
            return $this->fail === 'mount' ? new CommandResult(1, '', "mount failed\n", 1, false) : new CommandResult(0, $this->mounted, '', 1, false);
        }
        $operation = array_slice($argv, 5);

        return match (true) {
            array_slice($operation, 0, 3) === ['storage', 'volume', 'list'] => $this->answer('storage volume list', fn (): string => json_encode(array_map(
                static fn (string $name, array $config): array => ['name' => $name, 'type' => 'custom', 'config' => $config],
                array_keys($this->volumes),
                $this->volumes,
            ) + [count($this->volumes) => ['name' => 'tvm-1', 'type' => 'virtual-machine', 'config' => []]])),
            array_slice($operation, 0, 3) === ['storage', 'volume', 'copy'] => $this->answer('storage volume copy', function () use ($operation): void {
                $this->volumes[explode('/', $operation[5])[1]] = [];
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
            $operation[0] === 'launch' => $this->answer('launch', function () use ($operation): void {
                $this->instances[] = end($operation);
            }),
            $operation[0] === 'project' => $this->answer('project show', static fn (): string => "name: orbit-tasks\n"),
            $operation[0] === 'list' && in_array('csv', $operation, true) => $this->answer('list', fn (): string => implode('', array_map(static fn (string $name): string => $name."\n", $this->instances))),
            $operation[0] === 'list' => $this->answer('list', fn (): string => json_encode(array_map(static fn (string $name): array => ['name' => $name, 'config' => []], $this->instances))),
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
        if ($command[0] === 'cloud-init') {
            return new CommandResult(0, '{"status":"done","errors":[]}', '', 1, false);
        }
        $user = $command[0] === 'runuser' ? array_slice($command, 4) : $command;

        return match (true) {
            // The step command follows the line that sets VP_HOME.
            $user[0] === 'timeout' => $this->answer('setup step ['.substr($user[5], strrpos($user[5], "\n") + 1).']'),
            $user[0] === 'git' => $this->answer('git status', fn (): string => $this->status),
            $user[0] === 'sh' && str_contains($user[2], 'composer.json') => $this->answer('composer.json', static fn (): string => 'absent'),
            $user[0] === 'bash' && $user[1] === '-seu' => $this->answer('clone', static fn (): string => str_repeat('a', 40)."\n"),
            default => $this->answer($user[0] === 'bash' && str_contains($user[2], 'mkfs.ext4') ? 'format' : 'exec '.$user[0]),
        };
    }

    private function answer(string $operation, ?Closure $effect = null): CommandResult
    {
        if ($this->fail === $operation) {
            return new CommandResult(1, '', "Error: {$operation} failed\n", 1, false);
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

beforeEach(function (): void {
    Sleep::fake();
    $this->hostNode = Node::query()->create([
        'name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'nckrtl', 'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $this->host = new TaskVmHost($this->hostNode->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 4, 2, '4GiB', '40GiB');
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, 4, '10.44.0.128/25', [$this->host], 'http://10.44.0.3:8317', null, null, []));
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
            'tvm-template-'.$this->project->id.'-20261010040000' => ['user.orbit.ready' => 'true', 'user.orbit.repository' => REPO],
            'tvm-template-'.$this->project->id.'-20261011040000' => ['user.orbit.ready' => 'true', 'user.orbit.repository' => REPO],
            'tvm-template-'.$this->project->id.'-20261011050000' => [],
            'tvm-template-999-20261012040000' => ['user.orbit.ready' => 'true', 'user.orbit.repository' => REPO],
        ];

        $template = app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou');

        $mount = collect($this->incus->calls)->first(static fn (array $call): bool => $call[0] === '10.44.0.130');
        expect($template)->toBe('/home/orbit/apps/mealou/.orbit-workspace-template')
            ->and($this->incus->operations())->toContain(
                ['storage', 'volume', 'copy', '--', 'orbit-tasks/tvm-template-'.$this->project->id.'-20261011040000', 'orbit-tasks/tvm-1-workspace'],
                ['config', 'device', 'add', '--', 'tvm-1', 'workspace', 'disk', 'pool=orbit-tasks', 'source=tvm-1-workspace'],
            )
            ->and(array_slice($mount[1], 3))->toBe(['--', 'workspace', '/home/orbit/apps/mealou', 'orbit', 'orbit', '.orbit-workspace-template', '.orbit-cache', 'ms-playwright', 'composer'])
            ->and($mount[1][2])->toContain('/etc/fstab')->toContain('x-systemd.before=multi-user.target');
    });

    it('takes no template of an earlier repository, and asks for a build', function (): void {
        Queue::fake();
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261011040000' => ['user.orbit.ready' => 'true', 'user.orbit.repository' => 'https://github.com/old/mealou.git']];

        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBeNull()
            ->and(collect($this->incus->operations())->contains(static fn (array $operation): bool => ($operation[2] ?? null) === 'copy'))->toBeFalse();
        Queue::assertPushed(BuildTaskVmWorkspaceTemplate::class);
    });

    it('writes the fstab line only after the mount', function (): void {
        $script = TaskVmWorkspaceTemplates::MountScript;

        expect(strpos($script, 'mount -o noatime'))->toBeLessThan(strpos($script, '/etc/fstab'))
            ->and(strpos($script, 'findmnt'))->toBeLessThan(strpos($script, '/etc/fstab'));
    });

    it('finds the clone, the disk, and the mount in place when the claim runs again', function (): void {
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261011040000' => ['user.orbit.ready' => 'true', 'user.orbit.repository' => REPO]];
        app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou');
        $this->incus->calls = [];

        expect(app(TaskVmWorkspace::class)->template($vm, $this->group, '/home/orbit/apps/mealou'))->toBe('/home/orbit/apps/mealou/.orbit-workspace-template')
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

    it('starts from a fresh clone and logs a warning when a step fails or the volume holds no template', function (string $failure): void {
        Log::spy();
        $vm = tvm_template_vm($this->group);
        $this->incus->volumes = ['tvm-template-'.$this->project->id.'-20261011040000' => ['user.orbit.ready' => 'true', 'user.orbit.repository' => REPO]];
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
            'tvm-1-workspace' => [], 'tvm-77-workspace' => [], 'tvm-template-'.$this->project->id.'-20261011040000' => ['user.orbit.ready' => 'true', 'user.orbit.repository' => REPO], 'other' => [],
        ];

        app()->call([new SweepTaskVmHosts, 'handle']);

        expect(array_keys($this->incus->volumes))->toBe(['tvm-1-workspace', 'tvm-template-'.$this->project->id.'-20261011040000', 'other'])
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Ready);
    });

    it('deletes the templates of Projects that no longer run task VMs and queues a build for each one that does', function (): void {
        Queue::fake();
        $shared = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
        $this->incus->volumes = [
            'tvm-template-'.$this->project->id.'-20261011040000' => ['user.orbit.ready' => 'true', 'user.orbit.repository' => REPO],
            'tvm-template-'.$shared->id.'-20261011040000' => ['user.orbit.ready' => 'true', 'user.orbit.repository' => REPO],
            'tvm-template-'.$shared->id.'-20261011050000' => [],
        ];

        app()->call([new RefreshTaskVmWorkspaceTemplates, 'handle']);

        expect(array_keys($this->incus->volumes))->toBe(['tvm-template-'.$this->project->id.'-20261011040000', 'tvm-template-'.$shared->id.'-20261011050000']);
        Queue::assertPushed(BuildTaskVmWorkspaceTemplate::class, 1);
        Queue::assertPushed(BuildTaskVmWorkspaceTemplate::class, fn (BuildTaskVmWorkspaceTemplate $job): bool => $job->projectId === $this->project->id);
    });

    it('schedules the template refresh nightly after the base image refresh while task VMs are enabled', function (): void {
        Artisan::call('schedule:list');
        $event = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event instanceof CallbackEvent && $event->description === RefreshTaskVmWorkspaceTemplates::class);

        config()->set('task_vms.enabled', false);
        expect($event)->not->toBeNull()->and($event->expression)->toBe('0 4 * * *')->and($event->filtersPass(app()))->toBeFalse();
        config()->set('task_vms.enabled', true);
        expect($event->filtersPass(app()))->toBeTrue();
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
        $this->incus->volumes = [$old => ['user.orbit.ready' => 'true', 'user.orbit.repository' => REPO], 'tvm-template-'.$this->project->id.'-20261011043000' => []];
        $this->incus->instances = ['tvm-template-20261011043000', 'tvm-5'];
        $stages = [];

        $volume = app(IncusTaskVmWorkspaceTemplateBuilder::class)->build($this->host, $this->hostNode, $this->project, function (string $stage) use (&$stages): void {
            $stages[] = $stage;
        });

        expect($volume)->toBe('tvm-template-'.$this->project->id.'-20261012040000')
            ->and($stages)->toBe(['render', 'sweep', 'launch', 'cloud-init', 'volume', 'mount', 'clone', 'php', 'setup:composer-install', 'setup:build-assets', 'verify', 'detach', 'publish', 'delete-builder', 'prune'])
            ->and($this->incus->volumes)->toBe([$volume => ['user.orbit.project' => (string) $this->project->id, 'user.orbit.commit' => str_repeat('a', 40), 'user.orbit.repository' => REPO, 'user.orbit.ready' => 'true']])
            ->and($this->incus->instances)->toBe(['tvm-5'])
            ->and($this->incus->operations())->toContain(
                ['launch', '--vm', '--config', 'limits.cpu=2', '--config', 'limits.memory=4GiB', '--device', 'root,size=40GiB', '--device', 'eth0,network=orbittask0', '--device', 'eth0,security.port_isolation=true', '--', TaskVmHost::BaseImage, 'tvm-template-20261012040000'],
                ['storage', 'volume', 'create', '--type=block', '--', 'orbit-tasks', $volume, 'size=40GiB'],
                ['exec', '--cwd', '/home/orbit/apps/mealou/.orbit-workspace-template', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit', '--', 'tvm-template-20261012040000', 'runuser', '-u', 'orbit', '--', 'timeout', '120', 'bash', '-l', '-c', 'if vp=$(command -v vp); then export VP_HOME="$(dirname "$(dirname "$vp")")"; fi'."\n".'vp build'],
            )
            ->and(Cache::lock('task-vms:workspace-template:'.$this->hostNode->id, 10)->get())->toBeTrue();
    });

    it('deletes its builder and volume and keeps the current template when a step fails', function (string $failure, string $stage): void {
        $current = 'tvm-template-'.$this->project->id.'-20261011040000';
        $this->incus->volumes = [$current => ['user.orbit.ready' => 'true', 'user.orbit.repository' => REPO]];
        $failure === 'dirty' ? $this->incus->status = " M composer.lock\n" : $this->incus->fail = $failure;

        expect(fn () => app(IncusTaskVmWorkspaceTemplateBuilder::class)->build($this->host, $this->hostNode, $this->project))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.workspace_template_failed')->and($exception->getMessage())->toContain("stage [{$stage}]"));

        expect($this->incus->volumes)->toBe([$current => ['user.orbit.ready' => 'true', 'user.orbit.repository' => REPO]])
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
        expect(last($steps[0]))->toContain('export VP_HOME=')->toContain('python3 -c')->not->toContain(LifecycleStep::RestoreTiaBaseline)
            ->and((int) $steps[1][array_search('timeout', $steps[1], true) + 1])->toBeLessThanOrEqual(120);
    });

    it('refuses a second build on the host while one runs', function (): void {
        Cache::lock('task-vms:workspace-template:'.$this->hostNode->id, 60)->get();

        expect(fn () => app(IncusTaskVmWorkspaceTemplateBuilder::class)->build($this->host, $this->hostNode, $this->project))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.workspace_template_running'));
    });

    it('runs Node programs in the builder as orbit through the host', function (): void {
        $shell = new TaskVmBuilderShell(app(IncusHost::class), $this->host, $this->hostNode, 'tvm-template-1');

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
