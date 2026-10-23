<?php

declare(strict_types=1);

use App\Actions\Instances\CreateInstanceAction;
use App\Actions\Instances\DestroyInstanceAction;
use App\Actions\Tasks\CancelTaskGroupAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalException;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\NativeAppDevSourceOperationLock;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceLifecycle;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceRemoval;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentTransport;
use App\Infrastructure\AgentCommands\AgentTransports;
use App\Infrastructure\AgentCommands\NativeAgentCommandRunner;
use App\Infrastructure\AgentCommands\SshAgentTransport;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\LocalAgentBinary;
use Tests\Support\GitRemoteRedirect;
use Tests\Support\LifecycleSshExecutor;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\PlantedGitConfig;

pest()->group('privileged');

beforeAll(function (): void {
    $seed = orb76_repository_seed();
    new Filesystem()->makeDirectory($seed, 0o755, true);
    orb76_create_remote_repository($seed, $seed.'/origin.git');
});

afterAll(function (): void {
    new Filesystem()->deleteDirectory(orb76_repository_seed());
});

/** The real agent runner over the test's SSH stand-in, which runs the built agent binary on this machine. */
function orb76_agent_runner(SshExecutor $ssh, SshKeyProvider $keys, KnownHostsStore $knownHosts, string $sandbox): NativeAgentCommandRunner
{
    $transports = new class($ssh, $keys, $knownHosts) extends AgentTransports
    {
        public function __construct(private readonly SshExecutor $ssh, private readonly SshKeyProvider $keys, private readonly KnownHostsStore $knownHosts) {}

        public function for(Node $node): AgentTransport
        {
            return new SshAgentTransport($node, $this->ssh, $this->keys, $this->knownHosts);
        }
    };

    return new NativeAgentCommandRunner(app(AgentContract::class), LocalAgentBinary::delivery($sandbox.'/agent-delivery'), $transports);
}

/** The immutable seed is private to this file's worker; each case changes only its copy. */
function orb76_repository_seed(): string
{
    static $directory = null;

    return $directory ??= sys_get_temp_dir().'/orbit-source-seed-'.bin2hex(random_bytes(16));
}

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->sandbox = sys_get_temp_dir().'/orbit-app-instance-source-'.Str::uuid();
    $this->repository = $this->sandbox.'/origin.git';
    $this->appsRoot = $this->sandbox.'/apps';
    $this->remoteOrigin = 'ssh://git@example.test/acme/site.git';
    $this->files->makeDirectory($this->sandbox, 0o755, true);
    $this->previousDirectory = getcwd();
    chdir($this->sandbox);
    $this->files->copyDirectory(orb76_repository_seed(), $this->sandbox);

    $identity = posix_getpwuid(posix_geteuid());
    $groupIdentity = posix_getgrgid(posix_getegid());
    $user = is_array($identity) && is_string($identity['name'] ?? null) ? $identity['name'] : 'orbit';
    $group = is_array($groupIdentity) && is_string($groupIdentity['name'] ?? null)
        ? $groupIdentity['name']
        : $user;
    $account = new ManagedUserAccount($user, $group, $this->sandbox.'/home');
    $accounts = new class($account) implements ManagedUserAccountResolver
    {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
    $this->accounts = $accounts;
    $this->transport = new Orb76LocalSourceSshExecutor($this->remoteOrigin, $this->repository);
    $this->sourceLock = new NativeAppDevSourceOperationLock($this->sandbox.'/locks');
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 test';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $ssh = new DevelopmentSshExecutor($this->transport, $keys, $knownHosts);
    $this->ssh = $ssh;
    $this->boundary = new CheckoutRemovalBoundary(new ProtectedPathCatalog);
    $this->source = new RemoteDevelopmentInstanceSourceLifecycle(
        $ssh,
        $accounts,
        $this->boundary,
        app(RepositoryReadAccess::class),
        app(TaskVmGit::class),
    );
    $this->agents = orb76_agent_runner($this->transport, $keys, $knownHosts, $this->sandbox);
    $this->removal = new RemoteDevelopmentInstanceSourceRemoval(
        $this->agents,
        $accounts,
        $this->boundary,
        $this->sourceLock,
        app(RepositoryReadAccess::class),
    );

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.3',
        'user' => $user,
    ]);
    orbit_test_set_app_placement_role($this->node, production: false);
    $this->orbitApp = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => $this->remoteOrigin,
        'default_branch' => 'main',
        'root' => 'public',
    ]);
});

afterEach(function (): void {
    chdir($this->previousDirectory);
    $this->files->deleteDirectory($this->sandbox);
});

it('creates a task VM workspace from the Gateway mirror, with no clone or fetch in the VM', function (): void {
    orb76_https_origin($this);
    GitRemoteRedirect::global($this->repository, $this->remoteOrigin);
    $host = Node::query()->create(['name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'nckrtl']);
    $group = Task::topLevel()->create(['project_id' => $this->orbitApp->id, 'title' => 'Export', 'brief' => 'Add an export.', 'status' => TaskGroupStatus::Running]);
    TaskVm::query()->create([
        'group_id' => $group->id, 'host_node_id' => $host->id, 'node_id' => $this->node->id, 'provider' => 'incus', 'name' => 'tvm-1',
        'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.130', 'pi_token' => str_repeat('p', 64),
    ]);
    $name = 'task-'.$group->id;

    try {
        $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, $name, $name, $group);
        $this->source->prepare($instance, false);
        $resolution = $this->source->resolve($instance->refresh());
    } finally {
        GitRemoteRedirect::clear();
    }

    $checkout = $instance->checkout_path;
    $main = trim(orb76_run(['git', '-C', $this->repository, 'rev-parse', 'refs/heads/main'])->stdout);
    expect($resolution->branch)->toBe($name)
        ->and($resolution->startingCommit)->toBe($main)
        // Only the default branch and the task branch come from the mirror. The old in-VM fetch took every branch.
        ->and(trim(orb76_run(['git', '-C', $checkout, 'for-each-ref', '--format=%(refname)', 'refs/remotes'])->stdout))->toBe("refs/remotes/origin/HEAD\nrefs/remotes/origin/main");
    $network = [];
    foreach ($this->transport->commands as $command) {
        expect($command->protectedInput?->carriesGitCredential())->not->toBeTrue();
        if (str_contains((string) $command->input, 'allow_existing=$6') || str_contains((string) $command->input, 'instance_name=$6')) {
            $network[] = $command->arguments[array_key_last($command->arguments)];
        }
    }
    // Preparation runs `git init` instead of a clone, and resolution fetches nothing in the VM.
    expect($network)->toBe(['0', '0']);
});

it('starts a new task workspace at main tip and imports the newest cached ancestor', function (bool $cachedHead): void {
    Log::spy();
    orb76_https_origin($this);
    GitRemoteRedirect::global($this->repository, $this->remoteOrigin);
    $group = Task::topLevel()->create(['project_id' => $this->orbitApp->id, 'title' => 'Warm start', 'brief' => 'Start warm.', 'status' => TaskGroupStatus::Running]);
    orb76_task_vm($this->node)->update(['group_id' => $group->id]);
    ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => 'setup', 'name' => 'ci-cache', 'command' => '@orbit/ci-caches', 'timeout_seconds' => 60, 'position' => 0]);
    $work = $this->sandbox.'/work';
    $projects = ['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];
    mkdir($work.'/bin');
    copy(base_path('../../bin/ci-cache-ref'), $work.'/bin/ci-cache-ref');
    file_put_contents($work.'/.gitignore', "**/vendor/\n");
    foreach ($projects as $project) {
        mkdir($work.'/'.$project, 0o755, true);
        file_put_contents($work.'/'.$project.'/composer.lock', '{"packages":[]}');
        file_put_contents($work.'/'.$project.'/pint.json', '{}');
        orb76_cache_dependencies($work.'/'.$project);
    }
    orb76_run(['git', '-C', $work, 'add', '.']);
    orb76_run(['git', '-C', $work, 'commit', '-m', 'Cache inputs']);
    $previous = orb76_publish_ci_cache($this->sandbox, $projects);
    file_put_contents($work.'/README.md', "uncached main\n");
    orb76_run(['git', '-C', $work, 'commit', '-am', 'New main']);
    $main = trim(orb76_run(['git', '-C', $work, 'rev-parse', 'HEAD'])->stdout);
    if ($cachedHead) {
        orb76_publish_ci_cache($this->sandbox, $projects);
    }
    orb76_run(['git', '-C', $work, 'push', 'origin', 'main']);
    $selected = $cachedHead ? $main : $previous;

    try {
        $name = 'task-'.$group->id;
        $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, $name, $name, $group);
        $this->source->prepare($instance, false);
        $resolution = $this->source->resolve($instance->refresh());
        $checkout = $instance->checkout_path;
        expect($resolution->startingCommit)->toBe($main)
            ->and(trim(orb76_run(['git', '-C', $checkout, 'rev-parse', 'HEAD'])->stdout))->toBe($main)
            ->and(trim(orb76_run(['git', '-C', $checkout, 'rev-parse', 'refs/worktree/orbit/ci-baseline'])->stdout))->toBe($selected)
            ->and(trim(orb76_run(['git', '-C', $checkout, 'rev-parse', 'origin/main'])->stdout))->toBe($main)
            ->and(trim(orb76_run(['git', '-C', $checkout, 'for-each-ref', '--format=%(refname)', 'refs/orbit/ci-cache/'])->stdout))->toBe('refs/orbit/ci-cache/'.$selected);
        foreach ($projects as $project) {
            orb76_cache_dependencies($checkout.'/'.$project);
        }
        $import = orb76_run(['bash', '-c', 'cd "$1"; bash "$2"', '--', $checkout, resource_path('instances/ci-caches.sh')]);
        expect($import->stdout)->not->toContain('cold');
        foreach ($projects as $project) {
            expect(file_get_contents($checkout.'/'.$project.'/vendor/pint.cache'))->toBe(orb76_pint_cache($selected))
                ->and(file_exists($checkout.'/'.$project.'/.orbit-tia'))->toBeFalse();
        }
        Log::shouldHaveReceived('info')->with('CI cache baseline selected.', ['project_id' => $this->orbitApp->id, 'tip' => $main, 'baseline' => $selected, 'distance' => $cachedHead ? 0 : 1])->once();
        expect($this->source->resolve($instance)->startingCommit)->toBe($main);
    } finally {
        GitRemoteRedirect::clear();
    }
})->with(['uncached head' => false, 'cached head' => true]);

it('starts a cache-enabled new task workspace cold at main tip when cache refs are unavailable', function (bool $fetchFails): void {
    Log::spy();
    orb76_https_origin($this);
    GitRemoteRedirect::global($this->repository, $this->remoteOrigin);
    if ($fetchFails) {
        $runner = new class(app(ProcessRunner::class)) implements ProcessRunner
        {
            public function __construct(private ProcessRunner $runner) {}

            public function run(ProcessInvocation $invocation): CommandResult
            {
                return in_array('+refs/orbit/ci-cache/*:refs/orbit/ci-cache/*', $invocation->arguments, true)
                    ? new CommandResult(128, '', 'Cache ref permission denied.', 0, false)
                    : $this->runner->run($invocation);
            }
        };
        $this->source = new RemoteDevelopmentInstanceSourceLifecycle($this->ssh, $this->accounts, $this->boundary, app(RepositoryReadAccess::class), new TaskVmGit($runner, $this->sandbox.'/mirror', ['https', 'file']));
    }
    $group = Task::topLevel()->create(['project_id' => $this->orbitApp->id, 'title' => 'No baseline', 'brief' => 'No cache.', 'status' => TaskGroupStatus::Running]);
    orb76_task_vm($this->node)->update(['group_id' => $group->id]);
    ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => 'setup', 'name' => 'ci-cache', 'command' => '@orbit/ci-caches', 'timeout_seconds' => 60, 'position' => 0]);
    try {
        $name = 'task-'.$group->id;
        $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, $name, $name, $group);
        $this->source->prepare($instance, false);

        $main = trim(orb76_run(['git', '-C', $this->sandbox.'/work', 'rev-parse', 'HEAD'])->stdout);

        expect($this->source->resolve($instance)->startingCommit)->toBe($main)
            ->and(trim(orb76_run(['git', '-C', $instance->checkout_path, 'rev-parse', 'HEAD'])->stdout))->toBe($main)
            ->and(trim(orb76_run(['git', '-C', $instance->checkout_path, 'for-each-ref', '--format=%(refname)', 'refs/worktree/orbit/ci-baseline', 'refs/orbit/ci-cache/'])->stdout))->toBe('');
        Log::shouldHaveReceived('notice')->with('CI caches cold; no cached first-parent ancestor is available in the mirror.', ['project_id' => $this->orbitApp->id, 'tip' => $main])->once();
        if ($fetchFails) {
            Log::shouldHaveReceived('warning')->with('CI cache ref fetch failed; setup continues cold.', ['project_id' => $this->orbitApp->id, 'exit_code' => 128, 'stderr' => 'Cache ref permission denied.'])->once();
        }
    } finally {
        GitRemoteRedirect::clear();
    }
})->with(['no ancestor cache' => false, 'failed ref fetch' => true]);

it('does not require a cached start for an existing task branch or an incoming pull request', function (bool $incoming): void {
    orb76_https_origin($this);
    GitRemoteRedirect::global($this->repository, $this->remoteOrigin);
    $group = Task::topLevel()->create(['project_id' => $this->orbitApp->id, 'title' => 'Selected source', 'brief' => 'Keep source.', 'status' => TaskGroupStatus::Running, 'pr_branch' => $incoming ? 'feature' : null]);
    orb76_task_vm($this->node)->update(['group_id' => $group->id]);
    ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => 'setup', 'name' => 'ci-cache', 'command' => '@orbit/ci-caches', 'timeout_seconds' => 60, 'position' => 0]);
    $name = 'task-'.$group->id;
    $work = $this->sandbox.'/work';
    if (! $incoming) {
        orb76_run(['git', '-C', $work, 'checkout', '-b', $name]);
        orb76_run(['git', '-C', $work, 'commit', '--allow-empty', '-m', 'Existing task work']);
        orb76_run(['git', '-C', $work, 'push', 'origin', $name]);
    }
    $selected = trim(orb76_run(['git', '-C', $work, 'rev-parse', 'HEAD'])->stdout);
    try {
        $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, $name, $name, $group);
        $this->source->prepare($instance, false);

        expect($this->source->resolve($instance)->startingCommit)->toBe($selected);
    } finally {
        GitRemoteRedirect::clear();
    }
})->with(['existing task branch' => false, 'incoming pull request' => true]);

it('receives optional CI cache refs in an ordinary workspace through the Gateway mirror', function (bool $published): void {
    orb76_https_origin($this);
    GitRemoteRedirect::global($this->repository, $this->remoteOrigin);
    $main = trim(orb76_run(['git', '-C', $this->repository, 'rev-parse', 'refs/heads/main'])->stdout);
    $ref = 'refs/orbit/ci-cache/'.$main;
    if ($published) {
        orb76_run(['git', '-C', $this->repository, 'update-ref', $ref, $main]);
    }
    ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => 'setup', 'name' => 'ci-cache', 'command' => '@orbit/ci-caches', 'timeout_seconds' => 60, 'position' => 0]);
    try {
        $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'default');
        $this->source->prepare($instance, false);
        $resolution = $this->source->resolve($instance->refresh());
        expect($resolution->startingCommit)->toBe($main)
            ->and(trim(orb76_run(['git', '-C', $instance->checkout_path, 'rev-parse', 'HEAD'])->stdout))->toBe($main)
            ->and(trim(orb76_run(['git', '-C', $instance->checkout_path, 'rev-parse', 'refs/worktree/orbit/ci-baseline'])->stdout))->toBe($main);
        $refs = trim(orb76_run(['git', '-C', $instance->checkout_path, 'for-each-ref', '--format=%(refname)', 'refs/orbit/ci-cache/'])->stdout);
        expect($refs)->toBe($published ? $ref : '');
    } finally {
        GitRemoteRedirect::clear();
    }
})->with(['matching ref' => true, 'missing ref' => false]);

it('preserves a pre-existing same-origin checkout through the whole failed create and retry', function (): void {
    orb866_bind_create_sources($this);
    $path = $this->appsRoot.'/acme/unregistered';
    $this->files->makeDirectory(dirname($path), 0o755, true);
    orb76_run(['git', 'clone', $this->repository, $path]);
    orb76_run(['git', '-C', $path, 'checkout', 'main']);
    orb76_run(['git', '-C', $path, 'config', 'maintenance.auto', 'false']);
    orb76_run(['git', '-C', $path, 'config', 'user.name', 'Orbit Test']);
    orb76_run(['git', '-C', $path, 'config', 'user.email', 'orbit@example.test']);
    orb76_run(['git', '-C', $path, 'commit', '--allow-empty', '-m', 'Unpublished local work']);
    file_put_contents($path.'/README.md', "uncommitted local work\n");
    file_put_contents($path.'/untracked.txt', "untracked local work\n");
    $before = orb866_source_bytes($path);
    $data = new CreateInstanceData($this->orbitApp->id, $this->node->id, 'unregistered', null, null, 't3code/new');
    foreach ([1, 2] as $attempt) {
        try {
            app(CreateInstanceAction::class)->execute($data);
            $this->fail('Expected preparation to refuse the pre-existing checkout.');
        } catch (ResourceOperationException|RuntimeConvergenceException $exception) {
            expect(is_dir($path))->toBeTrue()->and(orb866_source_bytes($path))->toBe($before);
            expect($exception)->toBeInstanceOf(ResourceOperationException::class)
                ->and($exception->details['cleanup'])->toBe('incomplete');
        }
    }
});

it('retains an interrupted prepare without a receipt and cleans a lost response with a receipt', function (string $failure): void {
    orb866_bind_create_sources($this);
    $this->transport->prepareFailure = $failure;
    $path = $this->appsRoot.'/acme/interrupted';
    $data = new CreateInstanceData($this->orbitApp->id, $this->node->id, 'interrupted', null, null, 't3code/new');
    try {
        app(CreateInstanceAction::class)->execute($data);
        $this->fail('Expected the injected prepare failure.');
    } catch (ResourceOperationException|RuntimeConvergenceException $exception) {
        expect($exception->errorCode)->toBe('instance.clone_failed');
        if ($failure === 'before receipt') {
            expect($exception)->toBeInstanceOf(ResourceOperationException::class)
                ->and($exception->details['cleanup'])->toBe('incomplete')
                ->and(is_dir($path.'/.git'))->toBeTrue()
                ->and(Instance::query()->where('name', 'interrupted')->exists())->toBeTrue();
            $before = orb866_source_bytes($path);
            $this->transport->prepareFailure = null;
            expect(fn () => app(CreateInstanceAction::class)->execute($data))
                ->toThrow(ResourceOperationException::class, 'cleanup is incomplete');
            expect(orb866_source_bytes($path))->toBe($before);
        } else {
            expect(is_dir($path))->toBeFalse()
                ->and(Instance::query()->where('name', 'interrupted')->exists())->toBeFalse();
        }
    }
})->with(['interrupted before receipt' => 'before receipt', 'lost completed response' => 'lost response']);

it('starts a linked workspace from the deployed commit of the default instead of newer origin main', function (): void {
    $seed = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'default');
    $this->source->prepare($seed, false);
    $resolution = $this->source->resolve($seed);
    $seed->update(['seed_path' => $seed->checkout_path, 'seed_commit' => $resolution->startingCommit, 'seed_repository' => $seed->checkout_path]);
    orb178_advance_remote($this->sandbox, 'main');
    $workspace = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'task-seeded', 'task-seeded');
    $workspace->update(['source_prepare_id' => (string) Str::uuid()]);
    $this->source->prepare($workspace, false);
    $resolved = $this->source->resolve($workspace);
    $this->source->inspectPrepared($workspace);
    expect($resolved->startingCommit)->toBe($resolution->startingCommit)
        ->and($workspace->refresh()->seed_path)->toBe($seed->checkout_path)
        ->and($workspace->source_layout)->toBe('worktree')
        ->and(is_file($workspace->checkout_path.'/.git'))->toBeTrue()
        ->and(trim(orb76_run(['git', '-C', $workspace->checkout_path, 'rev-parse', '--path-format=absolute', '--git-common-dir'])->stdout))->toBe($seed->checkout_path.'/.git');
});

it('clones a task VM workspace from the mirror and preserves its prepared checkout on retry', function (): void {
    orb76_https_origin($this);
    $workspace = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'task-7', 'task-7');
    orb76_task_vm($this->node);
    $checkout = dirname($workspace->checkout_path).'/workspace';
    $workspace->update(['checkout_path' => $checkout, 'source_prepare_id' => (string) Str::uuid()]);
    GitRemoteRedirect::global($this->repository, $this->remoteOrigin);
    try {
        $this->source->prepare($workspace, false);
        $resolved = $this->source->resolve($workspace);
        file_put_contents($checkout.'/.git/info/exclude', "vendor/\n");
        $this->files->makeDirectory($checkout.'/vendor');
        file_put_contents($checkout.'/vendor/cache.php', "<?php\n");
        $inode = fileinode($checkout.'/vendor/cache.php');
        $this->source->prepare($workspace->refresh(), true);
        $this->source->inspectPrepared($workspace);
    } finally {
        GitRemoteRedirect::clear();
    }

    expect(fileinode($checkout.'/vendor/cache.php'))->toBe($inode)
        ->and(is_file($checkout.'/.git/orbit-source-prepare'))->toBeTrue()
        ->and($resolved->branch)->toBe('task-7')
        ->and(trim(orb76_run(['git', '-C', $checkout, 'status', '--porcelain'])->stdout))->toBe('');
});

it('refuses an unprepared checkout left by an old template claim', function (): void {
    $workspace = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'task-8', 'task-8');
    orb76_task_vm($this->node);
    $checkout = dirname($workspace->checkout_path).'/workspace';
    $workspace->update(['checkout_path' => $checkout, 'source_prepare_id' => (string) Str::uuid()]);
    $this->files->makeDirectory(dirname($checkout), 0o755, true);
    orb76_run(['git', 'clone', '--quiet', $this->repository, $checkout]);

    expect(fn () => $this->source->prepare($workspace, true))->toThrow(RuntimeConvergenceException::class);
    expect(is_file($checkout.'/.git/orbit-source-prepare'))->toBeFalse();
});

it('refuses the fixed workspace checkout on a Node that is not a task VM', function (): void {
    $workspace = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'task-6', 'task-6');
    $workspace->update(['checkout_path' => dirname($workspace->checkout_path).'/workspace', 'source_prepare_id' => (string) Str::uuid()]);

    expect(fn () => $this->source->prepare($workspace, false))->toThrow(RuntimeConvergenceException::class, 'unsafe checkout path');
});

it('does not transfer an attempt receipt to a replacement directory even with force', function (bool $failureRecord): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'owned');
    $instance->update(['source_prepare_id' => (string) Str::uuid(), 'failed_step' => $failureRecord ? 'source-prepare' : null, 'error_code' => $failureRecord ? 'instance.clone_failed' : null]);
    $this->source->prepare($instance, false);
    rename($instance->checkout_path, $this->sandbox.'/owned-backup');
    orb76_run(['git', 'clone', '--no-checkout', $this->repository, $instance->checkout_path]);
    copy($this->sandbox.'/owned-backup/.git/orbit-source-prepare', $instance->checkout_path.'/.git/orbit-source-prepare');
    expect(fn () => $this->source->inspectPrepared($instance))->toThrow(RuntimeConvergenceException::class);
    expect(fn () => $this->removal->inspect($instance, true))->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe('instance.source_ownership_mismatch'));
    expect(is_dir($instance->checkout_path))->toBeTrue();
})->with([false, true]);

it('refuses to move a checkout across a mount before any copy, and keeps it in place', function (): void {
    // A task VM workspace volume is mounted at the Project's apps directory, while `.orbit-removals` stays on the
    // root disk. mv would copy and delete the checkout there, and the identity check would fail after the copy.
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-cross-device');
    $member = orb180_record_source($this->removal, $instance, true);
    $checkout = (string) $instance->checkout_path;
    // The Node answers that the checkout is on another device, as it does without moving anything.
    $this->transport->crossDeviceOnce = true;

    // The step failure keeps a `cross-device:` line, so the command's Activity shows why the checkout stayed.
    expect(fn () => $this->removal->finalize($member))->toThrow(function (RuntimeConvergenceException $exception) use ($checkout): void {
        expect($exception->errorCode)->toBe('instance.removal_incomplete')
            ->and($exception->result?->exitCode)->toBe(1)
            ->and($exception->result?->stdout)->toBe("{\"state\":\"cross_device\"}\n")
            ->and($exception->result?->stderr)->toStartWith("cross-device: the checkout [{$checkout}]");
    });

    expect(is_dir($checkout.'/.git'))->toBeTrue()
        ->and(file_exists(orb180_quarantine_path($member)))->toBeFalse()
        ->and($member->refresh()->source_finalized_at)->toBeNull();

    expect($this->removal->finalize($member))->not->toBeNull()
        ->and(file_exists($checkout))->toBeFalse();
});

// Setup steps, development Processes, and agents run as the managed user and can write the checkout config. A planted
// URL rewrite to `ext::` makes the fetch fail closed; no planted command runs.
it('fetches at resolve without running a command planted in the checkout config', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-planted-fetch');
    $checkout = $instance->checkout_path;
    orb76_run(['git', '-C', $checkout, 'push', '-q', $this->repository, 'HEAD:refs/heads/planted']);
    PlantedGitConfig::plant($checkout, $this->sandbox.'/markers', [$this->repository, $this->remoteOrigin]);
    $this->source->inspectPrepared($instance);
    $instance->update(['branch_override' => 'planted']);

    expect(fn () => $this->source->resolve($instance->refresh()))->toThrow(RuntimeConvergenceException::class);
    expect(PlantedGitConfig::markers($this->sandbox.'/markers'))->toBe([]);
});

it('fetches at resolve past planted command keys without running them', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-planted-keys');
    $checkout = $instance->checkout_path;
    orb76_run(['git', '-C', $checkout, 'push', '-q', $this->repository, 'HEAD:refs/heads/planted-keys']);
    PlantedGitConfig::plant($checkout, $this->sandbox.'/markers', []);
    $this->source->inspectPrepared($instance);
    $instance->update(['branch_override' => 'planted-keys']);

    expect($this->source->resolve($instance->refresh())->branch)->toBe('planted-keys')
        ->and(PlantedGitConfig::markers($this->sandbox.'/markers'))->toBe([]);
});

describe('CheckoutProcesses', function (): void {
    it('stops the processes inside a task workspace before deleting it and leaves every other process alone', function (string $operation): void {
        $group = Task::topLevel()->create(['project_id' => $this->orbitApp->id, 'title' => 'Orphans', 'brief' => 'Brief.', 'status' => TaskGroupStatus::Running]);
        $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-'.$group->id, $group);
        $sibling = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-'.$group->id.'0');
        TaskVm::query()->create(['group_id' => $group->id, 'node_id' => $this->node->id, 'host_node_id' => $this->node->id, 'provider' => 'incus', 'name' => 'removal-vm', 'wireguard_ip' => '10.44.0.130', 'state' => TaskVmState::Ready]);
        $checkout = (string) $instance->checkout_path;
        // A copy of bash keeps its own behaviour under any name; a multi-call coreutils `sleep` does not.
        copy((string) new ExecutableFinder()->find('bash'), $checkout.'/orbit-test-shell');
        chmod($checkout.'/orbit-test-shell', 0o755);
        // A tmux server started in the workspace hosts sessions that work elsewhere: its name is all that tells it apart.
        copy((string) new ExecutableFinder()->find('bash'), $this->sandbox.'/tmux: server');
        chmod($this->sandbox.'/tmux: server', 0o755);
        $stopped = [
            'own cwd' => orb_checkout_process_spawn($checkout, 'bash'),
            'own executable' => orb_checkout_process_spawn($this->sandbox, $checkout.'/orbit-test-shell'),
        ];
        $kept = [
            'own tmux server' => orb_checkout_process_spawn($checkout, $this->sandbox.'/tmux: server'),
            'own sibling Instance' => orb_checkout_process_spawn((string) $sibling->checkout_path, 'bash'),
            'own outside' => orb_checkout_process_spawn($this->sandbox, 'bash'),
        ];

        try {
            if ($operation === 'finalize') {
                $this->removal->finalize(orb180_record_source($this->removal, $instance, true));
            } else {
                orb178_remove_source($this->removal, $instance, true);
            }

            expect(file_exists($checkout))->toBeFalse();
            foreach ($stopped as $label => $pid) {
                expect(orb_checkout_process_running($pid))->toBeFalse("{$label} process {$pid} still runs");
            }
            foreach ($kept as $label => $pid) {
                expect(orb_checkout_process_running($pid))->toBeTrue("{$label} process {$pid} was stopped");
            }
        } finally {
            foreach ([...$stopped, ...$kept] as $pid) {
                orb178_run_allow_failure(['kill', '-KILL', (string) $pid]);
            }
        }
    })->with(['finalize', 'remove']);

    it('stops development Instance processes before deleting their checkout', function (string $name, string $operation): void {
        $sameNode = null;
        if ($name === 'group-name') {
            $group = Task::topLevel()->create(['project_id' => $this->orbitApp->id, 'title' => 'Separate workspace', 'brief' => 'Brief.', 'status' => TaskGroupStatus::Running]);
            $name = 'task-'.$group->id;
            $vmNode = Node::query()->create(['name' => 'separate-task-vm', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.131', 'wireguard_ip' => '10.44.0.131']);
            TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $this->node->id, 'node_id' => $vmNode->id, 'provider' => 'incus', 'name' => 'separate-vm', 'wireguard_ip' => '10.44.0.131', 'state' => TaskVmState::Ready]);
        }
        if ($name === 'unowned') {
            $sameNode = Task::topLevel()->create(['project_id' => $this->orbitApp->id, 'title' => 'Not its workspace', 'brief' => 'Brief.', 'status' => TaskGroupStatus::Running]);
            $name = 'task-'.$sameNode->id;
        }
        $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, $name);
        if ($sameNode instanceof Task) {
            // The group's live task VM is on this Node, but the group does not own the Instance.
            TaskVm::query()->create(['group_id' => $sameNode->id, 'host_node_id' => $this->node->id, 'node_id' => $this->node->id, 'provider' => 'incus', 'name' => 'same-node-vm', 'wireguard_ip' => '10.44.0.132', 'state' => TaskVmState::Ready]);
        }
        $checkout = (string) $instance->checkout_path;
        $stopped = [
            'own cwd' => orb_checkout_process_spawn($checkout, 'bash'),
        ];

        try {
            if ($operation === 'finalize') {
                $this->removal->finalize(orb180_record_source($this->removal, $instance, true));
            } else {
                orb178_remove_source($this->removal, $instance, true);
            }

            expect(file_exists($checkout))->toBeFalse();
            foreach ($stopped as $label => $pid) {
                expect(orb_checkout_process_running($pid))->toBeFalse("{$label} process {$pid} still runs");
            }
        } finally {
            foreach ($stopped as $pid) {
                orb178_run_allow_failure(['kill', '-KILL', (string) $pid]);
            }
        }
    })->with(['named' => 'feature-processes', 'task name without a group' => 'task-999999', 'task name with a group on another Node' => 'group-name', 'task name on the group\'s VM Node without an owner' => 'unowned'])->with(['finalize', 'remove']);
});

it('removes receipt-owned crash states without failure fields teardown or unrelated cleanup', function (string $state, bool $force): void {
    $sibling = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'unrelated');
    $before = orb866_source_bytes($sibling->checkout_path);
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'crashed');
    $instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $this->source->prepare($instance, false);
    if ($state === 'source_resolved') {
        $resolution = $this->source->resolve($instance);
        $instance->update(['branch' => $resolution->branch, 'starting_commit' => $resolution->startingCommit]);
        file_put_contents($instance->checkout_path.'/partial-runtime', 'unpublished partial work');
    }
    $instance->update(['status' => $state, 'vite_port' => 5200]);
    expect($instance->failed_step)->toBeNull()->and($instance->error_code)->toBeNull();
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'generation_basis_node_id' => $instance->node_id,
        'domain' => 'crashed.acme.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    DB::table('vite_port_assignments')->insert(['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'port' => 5200]);
    ProjectLifecycleStep::query()->create(['project_id' => $instance->project_id, 'phase' => 'teardown', 'name' => 'must-not-run', 'command' => 'exit 1', 'timeout_seconds' => 30, 'position' => 0]);
    $teardown = new LifecycleSshExecutor;
    app()->instance(ProjectLifecycleRunner::class, $teardown->runner());
    // Removal must not need the origin to clean an interrupted create.
    $this->files->deleteDirectory($this->repository);
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');
    $removal = $action->execute($instance, $force);
    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($removal->members)->toHaveCount(1)
        ->and(is_dir($instance->checkout_path))->toBeFalse()
        ->and($teardown->inputs)->toBe([])
        ->and(orb866_source_bytes($sibling->checkout_path))->toBe($before);
    $this->assertModelMissing($instance);
    $this->assertModelMissing($route);
    $this->assertModelExists($sibling);
    $this->assertDatabaseMissing('vite_port_assignments', ['instance_id' => $instance->id]);
})->with(['reserved', 'checkout_prepared', 'source_resolved'])->with([false, true]);

it('keeps normal teardown for a healthy receipt-owned unrouted task workspace', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'healthy-workspace');
    $instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update(['status' => InstanceState::SourceResolved, 'task_workspace_routed' => false, 'branch' => $resolution->branch, 'starting_commit' => $resolution->startingCommit]);
    ProjectLifecycleStep::query()->create(['project_id' => $instance->project_id, 'phase' => 'teardown', 'name' => 'normal-teardown', 'command' => 'cleanup', 'timeout_seconds' => 30, 'position' => 0]);
    $teardown = new LifecycleSshExecutor;
    app()->instance(ProjectLifecycleRunner::class, $teardown->runner());
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');
    $removal = $action->execute($instance, true);
    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($teardown->inputs)->toHaveCount(1);
    $this->assertModelMissing($instance);
});

it('refuses to cascade an interrupted create into a registered worktree even with force', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'crash-parent');
    $instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update(['status' => InstanceState::SourceResolved, 'branch' => $resolution->branch, 'starting_commit' => $resolution->startingCommit]);
    $path = $this->appsRoot.'/acme/keep-worktree';
    orb76_run(['git', '-C', $instance->checkout_path, 'worktree', 'add', '-b', 'keep-worktree', $path, 'HEAD']);
    $worktree = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'keep-worktree');
    $worktree->update(['source_layout' => 'worktree', 'status' => InstanceState::SourceResolved, 'branch' => 'keep-worktree', 'starting_commit' => $resolution->startingCommit]);
    $before = orb866_source_bytes($instance->checkout_path);
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');
    expect(fn () => $action->execute($instance, true))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.remove_refused')->and($exception->getMessage())->toContain('linked worktrees'))
        ->and(orb866_source_bytes($instance->checkout_path))->toBe($before)
        ->and(is_dir($path))->toBeTrue();
    $this->assertModelExists($instance);
    $this->assertModelExists($worktree);
    $this->assertDatabaseCount('instance_removals', 0);
});

it('removes a checkout_prepared failed create with its partial checkout Route and Vite reservation', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'failed-create', 'missing');
    $this->source->prepare($instance, false);
    $this->files->deleteDirectory($this->repository);
    expect(fn () => $this->source->resolve($instance))->toThrow(RuntimeConvergenceException::class);
    $instance->update([
        'status' => InstanceState::CheckoutPrepared,
        'failed_step' => 'source_resolved',
        'error_code' => 'instance.branch_resolution_failed',
        'vite_port' => 5200,
    ]);
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'domain' => 'failed-create.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Failed, 'failed_step' => 'source_resolved', 'error_code' => 'instance.branch_resolution_failed']);
    DB::table('vite_port_assignments')->insert(['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'port' => 5200]);
    ProjectLifecycleStep::query()->create(['project_id' => $instance->project_id, 'phase' => 'teardown', 'name' => 'cleanup', 'command' => 'cleanup', 'timeout_seconds' => 30, 'position' => 0]);
    $teardown = new LifecycleSshExecutor;
    app()->instance(ProjectLifecycleRunner::class, $teardown->runner());
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    $removal = $action->execute($instance, $force);

    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($removal->members->sole()->runtime_published)->toBeFalse()
        ->and(is_dir($instance->checkout_path))->toBeFalse()
        ->and($teardown->inputs)->toBe([]);
    $this->assertModelMissing($instance);
    $this->assertModelMissing($route);
    $this->assertDatabaseMissing('vite_port_assignments', ['instance_id' => $instance->id]);
})->with([false, true]);

it('removes an absent pre-activation reserved worktree without touching its seed or siblings', function (string $mode, bool $legacy): void {
    $seedPath = $this->appsRoot.'/acme/seed';
    $siblingPath = $this->appsRoot.'/acme/sibling';
    $this->files->makeDirectory(dirname($seedPath), 0o755, true);
    orb76_run(['git', 'clone', '--branch', 'main', $this->repository, $seedPath]);
    orb76_run(['git', '-C', $seedPath, 'worktree', 'add', '-b', 'sibling', $siblingPath, 'HEAD']);
    $commit = trim(orb76_run(['git', '-C', $seedPath, 'rev-parse', 'HEAD'])->stdout);
    $group = Task::topLevel()->create([
        'project_id' => $this->orbitApp->id,
        'title' => 'Interrupted worktree preparation',
        'brief' => 'Clear the reservation that stopped before mkdir.',
        'status' => TaskGroupStatus::Todo,
    ]);
    $name = 'task-'.$group->id;
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, $name, $name, $group);
    $instance->update([
        'source_prepare_id' => 'absent-task-prepare',
        'task_workspace_routed' => false,
        'seed_selected' => true,
        'seed_path' => $seedPath,
        'seed_repository' => $seedPath,
        'seed_commit' => $commit,
    ]);
    $seedBytes = orb866_source_bytes($seedPath);
    $siblingBytes = orb866_source_bytes($siblingPath);
    $worktrees = orb76_run(['git', '-C', $seedPath, 'worktree', 'list', '--porcelain'])->stdout;
    // Run real preparation, stopping immediately before mkdir after the layout update persisted.
    $this->transport->prepareFailure = 'before directory';
    expect(fn () => $this->source->prepare($instance, false))->toThrow(function (RuntimeConvergenceException $exception): void {
        expect($exception->result?->exitCode)->toBe(75);
    });
    $instance->refresh();
    if ($legacy) {
        $instance->update(['source_prepare_id' => null]);
    }
    expect($instance->source_layout)->toBe('worktree')
        ->and($instance->status)->toBe(InstanceState::Reserved)
        ->and($instance->starting_commit)->toBeNull()
        ->and($instance->failed_step)->toBeNull()
        ->and($instance->error_code)->toBeNull()
        ->and(file_exists($instance->checkout_path))->toBeFalse();
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');
    if ($mode === 'cancel') {
        bind_task_node_reachability();
        app(TaskExtensionState::class)->enable();
        $cancelled = app(CancelTaskGroupAction::class)->execute($group);
        expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($cancelled->assistance_requested)->toBeFalse();
        $removal = InstanceRemoval::query()->sole();
    } else {
        if ($mode === 'retry') {
            $this->transport->beforeFinalization = static function (): void {
                throw new RuntimeException('Interrupted absent worktree finalization.');
            };
            expect(fn () => $action->execute($instance, true))->toThrow(InstanceRemovalException::class);
            $member = InstanceRemovalMember::query()->sole();
            expect($member->source_prepared_at)->not->toBeNull()
                ->and($member->source_finalized_at)->toBeNull();
            $this->assertModelExists($instance);
        }
        $removal = $action->execute($instance->refresh(), $mode !== 'normal');
    }
    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($removal->members->sole()->source_identity)->toBe('absent')
        ->and($removal->members->sole()->finalization_receipt)->not->toBeNull();
    $this->assertModelMissing($instance);
    expect(orb866_source_bytes($seedPath))->toBe($seedBytes)
        ->and(orb866_source_bytes($siblingPath))->toBe($siblingBytes)
        ->and(orb76_run(['git', '-C', $seedPath, 'worktree', 'list', '--porcelain'])->stdout)->toBe($worktrees);
})->with(['normal', 'forced', 'cancel', 'retry'])->with(['prepared' => false, 'legacy null prepare' => true]);

it('protects unconfirmed and post-activation source in the reserved worktree absent path', function (string $state, bool $force, bool $legacy): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'task-protected', 'task-protected');
    $instance->update(['source_layout' => 'worktree', 'source_prepare_id' => $legacy ? null : 'unconfirmed-prepare', 'task_workspace_routed' => false]);
    $seedPath = $this->appsRoot.'/acme/seed';
    $this->files->makeDirectory(dirname($seedPath), 0o755, true);
    orb76_run(['git', 'clone', '--branch', 'main', $this->repository, $seedPath]);
    if ($state === 'unconfirmed worktree') {
        orb76_run(['git', '-C', $seedPath, 'worktree', 'add', '-b', 'task-protected', $instance->checkout_path, 'HEAD']);
    } elseif ($state === 'foreign directory') {
        $this->files->makeDirectory($instance->checkout_path);
        file_put_contents($instance->checkout_path.'/foreign', 'not owned by preparation');
    } elseif ($state === 'symlink') {
        symlink($seedPath, $instance->checkout_path);
    } else {
        $this->orbitApp->update(['type' => 'monorepo']);
        $instance->update([
            'status' => $state === 'active' ? InstanceState::Active : InstanceState::SourceResolved,
            'starting_commit' => str_repeat('a', 40),
        ]);
    }
    $seedBytes = orb866_source_bytes($seedPath);
    $sourceBytes = is_dir($instance->checkout_path) ? orb866_source_bytes($instance->checkout_path) : null;
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    expect(fn () => $action->execute($instance, $force))->toThrow(ResourceOperationException::class);

    $this->assertModelExists($instance);
    expect(InstanceRemoval::query()->count())->toBe(0)
        ->and(orb866_source_bytes($seedPath))->toBe($seedBytes);
    if ($sourceBytes !== null) {
        expect(orb866_source_bytes($instance->checkout_path))->toBe($sourceBytes);
    }
})->with(['unconfirmed worktree', 'foreign directory', 'symlink', 'resolved', 'active'])->with([false, true])->with(['prepared' => false, 'legacy null prepare' => true]);

it('refuses a directory appearing before absent reserved worktree finalization', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'task-race', 'task-race');
    $instance->update(['source_layout' => 'worktree', 'source_prepare_id' => 'absent-prepare', 'task_workspace_routed' => false]);
    $this->files->makeDirectory(dirname($instance->checkout_path), 0o755, true);
    $this->transport->beforeFinalization = function () use ($instance): void {
        $this->files->makeDirectory($instance->checkout_path);
        file_put_contents($instance->checkout_path.'/foreign', 'appeared after acceptance');
    };
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    expect(fn () => $action->execute($instance, $force))->toThrow(InstanceRemovalException::class);

    $this->assertModelExists($instance);
    expect(file_get_contents($instance->checkout_path.'/foreign'))->toBe('appeared after acceptance')
        ->and(InstanceRemoval::query()->sole()->status)->toBe(InstanceRemovalStatus::Failed)
        ->and(InstanceRemovalMember::query()->sole()->finalization_receipt)->toBeNull();
})->with([false, true]);

it('removes a failed reserved create whose clone never made a checkout', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'failed-clone');
    $this->transport->prepareFailure = 'before directory';
    $this->files->deleteDirectory($this->repository);
    expect(fn () => $this->source->prepare($instance, false))->toThrow(RuntimeConvergenceException::class);
    $instance->update(['failed_step' => 'checkout_prepared', 'error_code' => 'instance.clone_failed']);
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    $removal = $action->execute($instance, $force);

    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($removal->members->sole()->source_identity)->toBe('absent')
        ->and($removal->members->sole()->route_outcome)->toBe('none');
    $this->assertModelMissing($instance);
})->with([false, true]);

it('removes a failed in-place registration without deleting its branch or shared repository', function (bool $force): void {
    $checkout = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'shared');
    $path = $this->appsRoot.'/acme/stuck-registration';
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'stuck-registration', $path, 'HEAD']);
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'stuck-registration');
    $instance->update([
        'source_layout' => 'worktree',
        'branch' => 'stuck-registration',
        'starting_commit' => $checkout->starting_commit,
        'failed_step' => 'registration',
        'error_code' => 'instance.registration_incomplete',
        'registration_request_id' => (string) Str::uuid(),
        'registration_original_path' => $path,
        'registration_authoritative_path' => $path,
        'registration_relocation_state' => 'relocating',
        'registration_repository_identity' => $this->orbitApp->repository_identity,
        'registration_common_repository_path' => $checkout->checkout_path.'/.git',
        'registration_source_digest' => str_repeat('a', 64),
        'registration_detached' => false,
    ]);
    file_put_contents($path.'/unfinished-agent-work', 'dirty adopted source');
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');
    if (! $force) {
        expect(fn () => $action->execute($instance, false))->toThrow(ResourceOperationException::class);
        $this->assertModelExists($instance);
        unlink($path.'/unfinished-agent-work');
    }

    $removal = $action->execute($instance, $force);

    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and(is_dir($path))->toBeFalse()
        ->and(is_dir($checkout->checkout_path.'/.git'))->toBeTrue()
        ->and(trim(orb76_run(['git', '-C', $checkout->checkout_path, 'rev-parse', 'refs/heads/stuck-registration'])->stdout))->toBe($checkout->starting_commit);
    $this->assertModelMissing($instance);
    $this->assertModelExists($checkout);
})->with([false, true]);

it('does not authorize reserved checkout deletion from incomplete or mismatched registration evidence', function (string $mismatch): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'reserved-existing');
    $instance->update([
        'status' => InstanceState::Reserved,
        'failed_step' => 'registration',
        'error_code' => 'instance.registration_incomplete',
        'registration_request_id' => (string) Str::uuid(),
        'registration_original_path' => $instance->checkout_path,
        'registration_authoritative_path' => $instance->checkout_path,
        'registration_relocation_state' => 'reserved',
        'registration_repository_identity' => $this->orbitApp->repository_identity,
        'registration_common_repository_path' => $instance->checkout_path.'/.git',
        'registration_source_digest' => str_repeat('a', 64),
        'registration_detached' => false,
    ]);
    match ($mismatch) {
        'request' => $instance->update(['registration_request_id' => null]),
        'digest' => $instance->update(['registration_source_digest' => null]),
        'move' => $instance->update(['registration_original_path' => $this->sandbox.'/external']),
        'authority' => $instance->update(['registration_authoritative_path' => $this->sandbox.'/external']),
        'commit' => $instance->update(['starting_commit' => str_repeat('a', 40)]),
        'repository' => $instance->update(['registration_repository_identity' => 'example.test/other']),
        'common' => $instance->update(['registration_common_repository_path' => $this->sandbox.'/other/.git']),
        'detached' => $instance->update(['registration_detached' => true]),
        'clone' => $instance->update(['failed_step' => 'checkout_prepared', 'registration_request_id' => null]),
    };
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    expect(fn () => $action->execute($instance, true))->toThrow(ResourceOperationException::class)
        ->and(is_dir($instance->checkout_path))->toBeTrue();
    $this->assertModelExists($instance);
    $this->assertDatabaseCount('instance_removals', 0);
})->with(['request', 'digest', 'move', 'authority', 'commit', 'repository', 'common', 'detached', 'clone']);

it('retries empty Project directory cleanup after absent-source finalization is interrupted', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'failed-clone-retry');
    $this->transport->prepareFailure = 'before directory';
    $this->files->deleteDirectory($this->repository);
    expect(fn () => $this->source->prepare($instance, false))->toThrow(RuntimeConvergenceException::class);
    $instance->update(['failed_step' => 'checkout_prepared', 'error_code' => 'instance.clone_failed']);
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');
    // The first finalization is lost before it runs, as a dropped connection leaves it.
    $this->transport->loseFinalizationOnce = true;

    expect(fn () => $action->execute($instance, false))
        ->toThrow(fn (InstanceRemovalException $exception) => expect($exception->errorCode)->toBe('instance.removal_incomplete'));

    $failedRemoval = InstanceRemoval::query()->sole();
    expect($failedRemoval->failed_step)->toBe(InstanceRemovalStep::SourceFinalization)
        ->and(is_dir(dirname($instance->checkout_path)))->toBeTrue()
        ->and(file_exists(orb180_receipt_path($failedRemoval->members->sole())))->toBeFalse();
    $this->assertModelExists($instance);

    $completed = $action->execute($instance->refresh(), false);

    expect($completed->id)->toBe($failedRemoval->id)
        ->and($completed->status)->toBe(InstanceRemovalStatus::Completed)
        ->and(is_dir(dirname($instance->checkout_path)))->toBeFalse()
        ->and(file_exists(orb180_receipt_path($completed->members->sole())))->toBeTrue();
    $this->assertModelMissing($instance);
});

it('removes a failed source_resolved create without treating its partial checkout as active source', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'failed-runtime');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update(['status' => InstanceState::SourceResolved, 'branch' => $resolution->branch, 'starting_commit' => $resolution->startingCommit, 'failed_step' => 'active', 'error_code' => 'instance.runtime_failed']);
    file_put_contents($instance->checkout_path.'/partial-runtime', 'unfinished');
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    $removal = $action->execute($instance, false);

    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and(is_dir($instance->checkout_path))->toBeFalse();
    $this->assertModelMissing($instance);
});

it('refuses non-active creation without complete failure evidence', function (?string $step, ?string $code): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'not-failed');
    $this->source->prepare($instance, false);
    $instance->update(['status' => InstanceState::CheckoutPrepared, 'failed_step' => $step, 'error_code' => $code]);
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    expect(fn () => $action->execute($instance, true))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.remove_refused'));

    $this->assertModelExists($instance);
    expect(is_dir($instance->checkout_path))->toBeTrue();
    $this->assertDatabaseCount('instance_removals', 0);
})->with([[null, null], ['source_resolved', null], [null, 'instance.branch_resolution_failed']]);

it('does not waive source origin ownership for a failed create', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'foreign-source');
    $this->source->prepare($instance, false);
    $instance->update(['status' => InstanceState::CheckoutPrepared, 'failed_step' => 'source_resolved', 'error_code' => 'instance.branch_resolution_failed']);
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'remote.origin.url', 'https://example.test/other.git']);
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    expect(fn () => $action->execute($instance, $force))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.source_origin_mismatch'));

    $this->assertModelExists($instance);
    expect(is_dir($instance->checkout_path))->toBeTrue();
    $this->assertDatabaseCount('instance_removals', 0);
})->with([false, true]);

it('refuses removal while a receipt-owned create lifecycle lock is still held', function (bool $force, bool $failureRecord): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'running-create', 'missing');
    $instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $this->source->prepare($instance, false);
    $instance->update(['status' => InstanceState::CheckoutPrepared, 'failed_step' => $failureRecord ? 'source_resolved' : null, 'error_code' => $failureRecord ? 'instance.branch_resolution_failed' : null]);
    $directory = $this->sandbox.'/environment-locks';
    orb895_native_removal_action($this->removal, $this->sourceLock, $directory);
    $clock = 0.0;
    app()->instance(InstanceEnvironmentOperationLock::class, new NativeInstanceEnvironmentOperationLock(
        $directory,
        new CommandDeadline,
        clock: static function () use (&$clock): float {
            return $clock;
        },
        wait: static function () use (&$clock): void {
            $clock += 31.0;
        },
    ));
    $action = app(DestroyInstanceAction::class);
    $createOwner = new NativeInstanceEnvironmentOperationLock($directory, new CommandDeadline);

    $createOwner->run([$instance->id], function () use ($instance, $action, $force): void {
        expect(fn () => $action->execute($instance, $force))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('env.operation_busy'));
    });

    $this->assertModelExists($instance);
    expect(is_dir($instance->checkout_path))->toBeTrue();
    $this->assertDatabaseCount('instance_removals', 0);
})->with([false, true])->with([false, true]);

it('creates independent clones from an existing remote branch and the exact fetched default branch', function (): void {
    $existing = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $fallback = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'feature');

    $this->source->prepare($existing, false);
    $existingResolution = $this->source->resolve($existing);
    $this->source->prepare($fallback, false);
    $fallbackResolution = $this->source->resolve($fallback);

    expect($existingResolution->branch)
        ->toBe('dev')
        ->and($existingResolution->startingCommit)
        ->toBe(trim(orb76_run(['git', '--git-dir='.$this->repository, 'rev-parse', 'refs/heads/dev'])->stdout))
        ->and($fallbackResolution->branch)
        ->toBe('feature')
        ->and($fallbackResolution->startingCommit)
        ->toBe(trim(orb76_run(['git', '--git-dir='.$this->repository, 'rev-parse', 'refs/heads/main'])->stdout))
        ->and(is_dir($existing->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(is_dir($fallback->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(dirname($existing->checkout_path))
        ->toBe(dirname($fallback->checkout_path));
});

it('creates an explicit missing branch independently of the Instance name and preserves an existing local branch', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 't3-1a2b3c4d');
    $instance->update(['branch_override' => 't3code/1a2b3c4d']);
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    expect($resolution->branch)->toBe('t3code/1a2b3c4d')
        ->and($resolution->startingCommit)->toBe(trim(orb76_run(['git', '--git-dir='.$this->repository, 'rev-parse', 'refs/heads/main'])->stdout));

    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.email', 'test@example.test']);
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.name', 'Test']);
    orb76_run(['git', '-C', $instance->checkout_path, 'commit', '--allow-empty', '-m', 'Local commit']);
    $local = trim(orb76_run(['git', '-C', $instance->checkout_path, 'rev-parse', 'HEAD'])->stdout);
    expect($this->source->resolve($instance)->startingCommit)->toBe($local);
});

it('clones a gh_cli Project with the Gateway GitHub CLI token only on protected input', function (?string $token): void {
    app()->instance(GitHubCliToken::class, new readonly class($token) implements GitHubCliToken
    {
        public function __construct(private ?string $token) {}

        public function token(): string
        {
            return $this->token ?? throw new ResourceOperationException('github.cli_unauthenticated', 'No login.');
        }
    });
    $recorder = new class implements SshExecutor
    {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        public ?string $protectedInput = null;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;
            $stream = $command->protectedInput?->stream();
            $this->protectedInput = is_resource($stream) ? (string) stream_get_contents($stream) : null;

            return new CommandResult(0, '', '', 1, false);
        }
    };
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 test';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $source = new RemoteDevelopmentInstanceSourceLifecycle(
        new DevelopmentSshExecutor($recorder, $keys, $knownHosts),
        $this->accounts,
        $this->boundary,
        app(RepositoryReadAccess::class),
        app(TaskVmGit::class),
    );
    $this->orbitApp->update([
        'repository_url' => 'git@github.com:acme/private.git',
        'source_access' => ProjectSourceAccess::GhCli,
    ]);
    $instance = orb76_source_instance($this->orbitApp->refresh(), $this->node, $this->appsRoot, 'horizon');

    if ($token === null) {
        expect(fn () => $source->prepare($instance, false))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('github.cli_unauthenticated'))
            ->and($recorder->commands)->toBe([]);

        return;
    }

    $source->prepare($instance, false);

    expect($recorder->commands)->toHaveCount(1)
        ->and($recorder->commands[0]->input)->toBeNull()
        ->and(implode(' ', $recorder->commands[0]->arguments))->not->toContain($token)
        ->and($recorder->protectedInput)->toContain(base64_encode("x-access-token:{$token}"), 'git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false clone');
})->with([
    'logged in' => ['gho_sentinel000000000000000000'],
    'not logged in' => [null],
]);

it('uses the Project default branch for the reserved default identity', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'default');

    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);

    expect($resolution->branch)
        ->toBe('main')
        ->and($resolution->startingCommit)
        ->toBe(trim(orb76_run(['git', '--git-dir='.$this->repository, 'rev-parse', 'refs/heads/main'])->stdout))
        ->and($instance->checkout_path)
        ->toEndWith('/acme/default');
});

it('uses an existing explicit branch for any instance identity', function (): void {
    $instance = orb76_source_instance(
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'default',
        'dev',
    );

    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);

    expect($resolution->branch)
        ->toBe('dev')
        ->and($resolution->startingCommit)
        ->toBe(trim(orb76_run(['git', '--git-dir='.$this->repository, 'rev-parse', 'refs/heads/dev'])->stdout));
});

it('creates a task-named branch from the default branch when the remote task branch is missing', function (): void {
    $instance = orb76_source_instance(
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'task-12',
        'task-12',
    );

    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);

    expect($resolution->branch)
        ->toBe('task-12')
        ->and($resolution->startingCommit)
        ->toBe(trim(orb76_run(['git', '--git-dir='.$this->repository, 'rev-parse', 'refs/heads/main'])->stdout))
        ->and(trim(orb76_run(['git', '-C', $instance->checkout_path, 'symbolic-ref', '--short', 'HEAD'])->stdout))
        ->toBe('task-12');
});

it('creates a missing explicit branch for the default Instance from the default commit', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'default', 'missing');
    $this->source->prepare($instance, false);
    expect($this->source->resolve($instance)->branch)->toBe('missing');
});

it('checks local HEAD before rename without contacting origin or changing Git source', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    orb76_run(['git', '-C', $instance->checkout_path, 'branch', '-m', 't3code/login']);
    $this->transport->commands = [];
    $this->source->assertBranchCheckedOut($instance, 't3code/login');
    orb76_run(['git', '-C', $instance->checkout_path, 'remote', 'set-url', 'origin', 'https://example.test/acme/site.git']);
    $this->source->assertBranchCheckedOut($instance, 't3code/login');
    expect(fn () => $this->source->assertBranchCheckedOut($instance, 'dev'))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('instance.branch_not_checked_out'));
    orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '--detach']);
    expect(fn () => $this->source->assertBranchCheckedOut($instance, 't3code/login'))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('instance.branch_not_checked_out'));
    foreach ($this->transport->commands as $command) {
        expect($command->input)->not->toContain('git_read', ' fetch ', ' checkout ', ' reset ');
    }
});

it('reports a held checkout lifecycle lock before rename without leaving a lock file', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $checkout = (string) $instance->checkout_path;
    $legacyLock = '/tmp/orbit-lifecycle-'.posix_geteuid().'-'.hash('sha256', $checkout).'.lock';
    $held = fopen($checkout, 'r');
    expect($held !== false && flock($held, LOCK_EX | LOCK_NB))->toBeTrue();

    try {
        expect(fn () => $this->source->assertBranchCheckedOut($instance, 'main'))
            ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('instance.lifecycle_busy'));
    } finally {
        fclose($held);
    }

    $this->source->assertBranchCheckedOut($instance, (string) $instance->branch);
    expect(file_exists($legacyLock))->toBeFalse();
});

it('inspects unresolved prepared repositories and absent reserved paths without force', function (string $sourceState): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'stuck');
    $this->files->ensureDirectoryExists($this->appsRoot);
    $instance->update(['failed_step' => 'source-prepare', 'error_code' => 'instance.clone_failed']);
    $expectedCommit = str_repeat('0', 40);
    if ($sourceState !== 'absent') {
        $head = $sourceState === 'prepared with valid HEAD' ? 'main' : 'unborn';
        orb76_run(['git', '--git-dir='.$this->repository, 'symbolic-ref', 'HEAD', 'refs/heads/'.$head]);
        if ($head === 'main') {
            $expectedCommit = trim(orb76_run(['git', '--git-dir='.$this->repository, 'rev-parse', 'refs/heads/main'])->stdout);
        }
        $this->source->prepare($instance, false);
        $instance->update(['status' => InstanceState::CheckoutPrepared]);
    }
    $inventory = $this->removal->inspect($instance, false);
    expect($inventory->startingCommit)->toBe($expectedCommit)
        ->and($inventory->branch)->toBeNull()
        ->and($inventory->linkedWorktreePaths)->toBe([$instance->checkout_path]);
    $member = orb180_record_source($this->removal, $instance, false, activate: false);
    $receipt = $this->removal->finalize($member);
    orb182_clear_test_route($member);
    $member->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted', 'source_finalized_at' => now(), 'finalization_receipt' => $receipt]);
    expect(is_dir($instance->checkout_path))->toBeFalse()
        ->and($this->removal->revalidate($member))->toBe(InstanceSourceRevalidationState::Completed)
        ->and($this->removal->finalize($member))->toBe($receipt);
})->with(['absent', 'prepared with valid HEAD', 'prepared with unborn HEAD']);

it('makes preparation idempotent and uses only fixed source-control commands', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');

    $this->source->prepare($instance, false);
    $this->source->prepare($instance, true);
    $this->source->inspectPrepared($instance);
    $resolution = $this->source->resolve($instance);
    $this->source->inspectResolved($instance);

    $inputs = implode("\n", array_map(
        static fn (RemoteCommand $command): string => $command->input ?? '',
        $this->transport->commands,
    ));

    expect($resolution->branch)
        ->toBe('dev')
        ->and(substr_count($inputs, 'git -c core.hooksPath=/dev/null -c core.fsmonitor=false clone --no-checkout --origin origin --'))
        ->toBe(2)
        ->and($inputs)
        ->not->toContain('caddy', 'certificate', 'dns', 'php-fpm', 'systemctl', 'hostname');

    foreach ($this->transport->commands as $command) {
        expect(array_slice($command->arguments, 0, 3))->toBe(['bash', '-seu', '--']);
    }
});

it('inspects prepared source by its configured origin despite an insteadOf rule', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    orb76_insteadof_rule($instance->checkout_path, $this->sandbox);

    $this->source->inspectPrepared($instance);
    $this->source->prepare($instance, true);

    expect($this->source->resolve($instance)->branch)->toBe('dev');

    orb76_run(['git', '-C', $instance->checkout_path, 'remote', 'set-url', 'origin', $this->sandbox.'/other.git']);

    expect(fn () => $this->source->inspectPrepared($instance))
        ->toThrow(RuntimeConvergenceException::class);
});

it('preserves pre-existing source when fresh creation fails in reserved in either removal mode', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'pre-existing');
    $this->files->makeDirectory(dirname($instance->checkout_path), 0o755, true);
    orb76_run(['git', 'clone', '--no-checkout', '--origin', 'origin', '--', $this->repository, $instance->checkout_path]);
    $sentinel = $instance->checkout_path.'/untracked-source';
    file_put_contents($sentinel, 'pre-existing work');
    expect(fn () => $this->source->prepare($instance, false))->toThrow(RuntimeConvergenceException::class);
    $instance->update(['failed_step' => 'checkout_prepared', 'error_code' => 'instance.clone_failed']);
    $before = $instance->fresh()->getAttributes();
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    expect(fn () => $action->execute($instance, $force))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.remove_refused'));

    $this->assertModelExists($instance);
    expect($instance->fresh()->getAttributes())->toBe($before)
        ->and(is_dir($instance->checkout_path.'/.git'))->toBeTrue()
        ->and(file_get_contents($sentinel))->toBe('pre-existing work');
    $this->assertDatabaseCount('instance_removals', 0);
})->with([false, true]);

it('refuses matching pre-existing source for a fresh reservation and resumes it only after an interruption', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->files->makeDirectory(dirname($instance->checkout_path), 0o755, true);
    orb76_run([
        'git',
        'clone',
        '--no-checkout',
        '--origin',
        'origin',
        '--',
        $this->repository,
        $instance->checkout_path,
    ]);

    expect(fn () => $this->source->prepare($instance, false))
        ->toThrow(RuntimeConvergenceException::class);

    $this->source->prepare($instance, true);
    expect(is_dir($instance->checkout_path.'/.git'))->toBeTrue();
});

it('refuses dirty and unpublished source unless force is explicit', function (string $mutation): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');

    if ($mutation === 'dirty') {
        file_put_contents($instance->checkout_path.'/dirty.txt', 'dirty');
    } else {
        orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.name', 'Orbit Test']);
        orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.email', 'orbit@example.test']);
        file_put_contents($instance->checkout_path.'/unpublished.txt', 'unpublished');
        orb76_run(['git', '-C', $instance->checkout_path, 'add', 'unpublished.txt']);
        orb76_run(['git', '-C', $instance->checkout_path, 'commit', '-m', 'Unpublished']);
    }

    expect(fn () => orb178_remove_source($this->removal, $instance, false))
        ->toThrow(RuntimeConvergenceException::class);
    expect(is_dir($instance->checkout_path))->toBeTrue();

    orb178_remove_source($this->removal, $instance, true);
    expect(file_exists($instance->checkout_path))->toBeFalse();
})->with(['dirty', 'unpublished']);

it('names the refused identity check without waiving it in normal or forced removal', function (
    string $mutation,
    bool $force,
    string $code,
): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $decoy = $this->sandbox.'/decoy';
    $this->files->makeDirectory($decoy, 0o755, true);
    file_put_contents($decoy.'/sentinel', 'keep');

    match ($mutation) {
        'origin' => orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'remote',
            'set-url',
            'origin',
            'ssh://git@example.test/wrong.git',
        ]),
        'symlink' => (function () use ($instance, $decoy): void {
            $this->files->deleteDirectory($instance->checkout_path);
            symlink($decoy, $instance->checkout_path);
        })(),
        'branch' => orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '-b', 'elsewhere']),
    };

    expect(fn () => orb178_remove_source($this->removal, $instance, $force))
        ->toThrow(
            function (RuntimeConvergenceException $exception) use ($code, $instance): void {
                expect($exception->errorCode)
                    ->toBe($code)
                    ->and($exception->getMessage())
                    ->toStartWith("Instance [{$instance->name}] source ")
                    ->not->toContain($instance->checkout_path, 'example.test');
            },
        );
    expect(file_exists($decoy.'/sentinel'))
        ->toBeTrue()
        ->and(file_exists($instance->checkout_path) || is_link($instance->checkout_path))
        ->toBeTrue();
})->with([
    'normal origin' => ['origin', false, 'instance.source_origin_mismatch'],
    'forced origin' => ['origin', true, 'instance.source_origin_mismatch'],
    'normal symlink' => ['symlink', false, 'instance.source_path_mismatch'],
    'forced symlink' => ['symlink', true, 'instance.source_path_mismatch'],
    'normal branch' => ['branch', false, 'instance.source_branch_mismatch'],
    'forced branch' => ['branch', true, 'instance.source_branch_mismatch'],
]);

it('refuses a checkout with shared Git administration as a layout mismatch in either mode', function (bool $force): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $sharedGitDirectory = $this->sandbox.'/shared.git';
    $this->files->copyDirectory($instance->checkout_path.'/.git', $sharedGitDirectory);
    file_put_contents($instance->checkout_path.'/.git/commondir', "{$sharedGitDirectory}\n");

    expect(trim(orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'rev-parse',
        '--git-common-dir',
    ])->stdout))
        ->toBe($sharedGitDirectory);
    expect(fn () => orb178_remove_source($this->removal, $instance, $force))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_layout_mismatch');
        });
    expect(is_dir($instance->checkout_path))
        ->toBeTrue()
        ->and(is_dir($sharedGitDirectory))
        ->toBeTrue();
})->with([
    'normal removal' => false,
    'force' => true,
]);

it('uses current remote publication evidence without changing the checkout index refs or object store', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $beforeRefs = orb76_run(['git', '-C', $instance->checkout_path, 'show-ref'])->stdout;
    $beforeIndex = file_get_contents($instance->checkout_path.'/.git/index');
    $tip = orb178_advance_remote($this->sandbox, 'published-descendant');
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/main']);
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/dev']);

    expect(
        orb178_run_allow_failure([
            'git',
            '-C',
            $instance->checkout_path,
            'cat-file',
            '-e',
            "{$tip}^{commit}",
        ])->succeeded(),
    )
        ->toBeFalse()
        ->and($this->removal->inspect($instance, false)->startingCommit)
        ->toBe($instance->starting_commit)
        ->and(orb76_run(['git', '-C', $instance->checkout_path, 'show-ref'])->stdout)
        ->toBe($beforeRefs)
        ->and(file_get_contents($instance->checkout_path.'/.git/index'))
        ->toBe($beforeIndex)
        ->and(
            orb178_run_allow_failure([
                'git',
                '-C',
                $instance->checkout_path,
                'cat-file',
                '-e',
                "{$tip}^{commit}",
            ])->succeeded(),
        )
        ->toBeFalse();
});

it('keeps the Git index byte-for-byte unchanged through clean inspection and later refusal', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $index = $instance->checkout_path.'/.git/index';
    expect(touch($instance->checkout_path.'/README.md', time() + 10))->toBeTrue();
    $beforeBytes = file_get_contents($index);
    $beforeMtime = orb76_run(['stat', '-c', '%y', $index])->stdout;

    expect($this->removal->inspect($instance, false)->checkoutPath)->toBe($instance->checkout_path);
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/main']);
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/dev']);

    expect(fn () => $this->removal->inspect($instance, false))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(file_get_contents($index))
        ->toBe($beforeBytes)
        ->and(orb76_run(['stat', '-c', '%y', $index])->stdout)
        ->toBe($beforeMtime)
        ->and(orb76_run(['git', '-C', $instance->checkout_path, 'status', '--porcelain=v1'])->stdout)
        ->toBe('');
});

it('skips dirty and remote publication reads for forced removal', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    file_put_contents($instance->checkout_path.'/dirty.txt', 'dirty');
    orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'remote',
        'set-url',
        'origin',
        'https://example.test/acme/site.git',
    ]);
    $this->transport->commands = [];

    $inventory = $this->removal->inspect($instance, true);
    $this->removal->remove($instance, $inventory, true);

    expect(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and($this->transport->commands)
        ->toHaveCount(2);
});

it('returns linked-worktree inventory and refuses deletion with every path and branch intact', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $worktree = $this->appsRoot.'/acme/linked';
    orb76_run(['git', '-C', $instance->checkout_path, 'worktree', 'add', '-b', 'linked', $worktree, 'HEAD']);
    $refs = orb76_run(['git', '-C', $instance->checkout_path, 'show-ref'])->stdout;

    $inventory = $this->removal->inspect($instance, true);

    expect($inventory->linkedWorktreePaths)
        ->toBe([$instance->checkout_path, $worktree])
        ->and(fn () => $this->removal->remove($instance, $inventory, true))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_worktrees_mismatch');
        })
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue()
        ->and(is_dir($worktree))
        ->toBeTrue()
        ->and(orb76_run(['git', '-C', $instance->checkout_path, 'show-ref'])->stdout)
        ->toBe($refs)
        ->and(orb76_run(['git', '--git-dir='.$this->repository, 'show-ref'])->stdout)
        ->toContain('refs/heads/dev');
});

it('removes a checkout whose only other worktree lost its directory', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    // A test run adds a worktree under /tmp, and /tmp is cleared before the workspace is removed.
    $scratch = $this->sandbox.'/tmp-worktree';
    orb76_run(['git', '-C', $instance->checkout_path, 'worktree', 'add', '-b', 'scratch', $scratch, 'HEAD']);
    orb76_run(['rm', '-rf', $scratch]);

    $inventory = $this->removal->inspect($instance, true);
    $this->removal->remove($instance, $inventory, true);

    expect($inventory->linkedWorktreePaths)->toBe([$instance->checkout_path])
        ->and(is_dir($instance->checkout_path))->toBeFalse();
});

it('refuses a replacement or changed canonical origin between inspection and deletion', function (
    string $mutation,
    bool $force,
    string $code,
): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $inventory = $this->removal->inspect($instance, $force);

    if ($mutation === 'replacement') {
        expect(rename($instance->checkout_path, $this->sandbox.'/original'))->toBeTrue();
        orb76_run(['git', 'clone', '--no-checkout', $this->repository, $instance->checkout_path]);
        orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '-b', 'dev', $instance->starting_commit]);
    } else {
        orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'remote',
            'set-url',
            'origin',
            'ssh://git@example.test/other/site.git',
        ]);
    }

    expect(fn () => $this->removal->remove($instance, $inventory, $force))
        ->toThrow(function (RuntimeConvergenceException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code);
        })
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
})->with([
    'normal replacement' => ['replacement', false, 'instance.removal_conflict'],
    'forced replacement' => ['replacement', true, 'instance.removal_conflict'],
    'normal origin change' => ['origin', false, 'instance.source_origin_mismatch'],
    'forced origin change' => ['origin', true, 'instance.source_origin_mismatch'],
]);

it('removes a checkout whose origin an insteadOf rule rewrites', function (bool $force): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    orb76_insteadof_rule($instance->checkout_path, $this->sandbox);

    orb178_remove_source($this->removal, $instance, $force);

    expect(file_exists($instance->checkout_path))->toBeFalse();
})->with([
    'normal' => false,
    'forced' => true,
]);

it('still refuses a different origin under an insteadOf rule', function (bool $force): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    orb76_insteadof_rule($instance->checkout_path, $this->sandbox);
    orb76_run(['git', '-C', $instance->checkout_path, 'remote', 'set-url', 'origin', 'ssh://git@example.test/other/site.git']);

    expect(fn () => orb178_remove_source($this->removal, $instance, $force))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_origin_mismatch');
        })
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
})->with([
    'normal' => false,
    'forced' => true,
]);

it('accepts an equivalent supported origin at the destructive boundary', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $inventory = $this->removal->inspect($instance, true);
    orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'remote',
        'set-url',
        'origin',
        'https://example.test/acme/site.git',
    ]);

    $this->removal->remove($instance, $inventory, true);

    expect(file_exists($instance->checkout_path))->toBeFalse();
});

it('removes supported URL authorities', function (string $origin, string $identity, bool $force): void {
    $this->orbitApp->forceFill([
        'repository_url' => $origin,
        'repository_identity' => $identity,
    ])->save();
    $this->transport->remoteOrigin = $origin;
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');

    orb178_remove_source($this->removal, $instance, $force);

    expect(file_exists($instance->checkout_path))->toBeFalse();
})->with([
    'normal bracketed IPv6 HTTPS' => [
        'https://[2001:db8::1]:8443/acme/site.git',
        '[2001:db8::1]/acme/site',
        false,
    ],
    'forced bracketed IPv6 HTTPS' => [
        'https://[2001:db8::1]:8443/acme/site.git',
        '[2001:db8::1]/acme/site',
        true,
    ],
    'normal bracketed IPv6 SSH' => [
        'ssh://git@[2001:db8::1]:2222/acme/site.git',
        '[2001:db8::1]/acme/site',
        false,
    ],
    'forced bracketed IPv6 SSH' => [
        'ssh://git@[2001:db8::1]:2222/acme/site.git',
        '[2001:db8::1]/acme/site',
        true,
    ],
    'normal HTTPS with an empty port' => [
        'https://example.test:/acme/site.git',
        'example.test/acme/site',
        false,
    ],
    'forced HTTPS with port zero' => [
        'https://example.test:0/acme/site.git',
        'example.test/acme/site',
        true,
    ],
    'normal HTTPS with a leading-zero port' => [
        'https://example.test:00001/acme/site.git',
        'example.test/acme/site',
        false,
    ],
    'forced HTTPS with a signed port' => [
        'https://example.test:+22/acme/site.git',
        'example.test/acme/site',
        true,
    ],
    'normal SSH with port zero' => [
        'ssh://git@example.test:0/acme/site.git',
        'example.test/acme/site',
        false,
    ],
    'forced SSH with an empty port' => [
        'ssh://git@example.test:/acme/site.git',
        'example.test/acme/site',
        true,
    ],
    'forced SSH with a leading-zero port' => [
        'ssh://git@example.test:00001/acme/site.git',
        'example.test/acme/site',
        true,
    ],
    'normal SSH with a signed port' => [
        'ssh://git@example.test:+22/acme/site.git',
        'example.test/acme/site',
        false,
    ],
]);

it('refuses malformed origin ports at the destructive boundary', function (string $origin, bool $force): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $inventory = $this->removal->inspect($instance, $force);
    orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'remote',
        'set-url',
        'origin',
        $origin,
    ]);

    expect(fn () => $this->removal->remove($instance, $inventory, $force))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_origin_mismatch');
        });
    expect(is_dir($instance->checkout_path))->toBeTrue();
})->with([
    'normal HTTPS' => ['https://example.test:notaport/acme/site.git', false],
    'forced HTTPS' => ['https://example.test:notaport/acme/site.git', true],
    'normal SSH' => ['ssh://git@example.test:notaport/acme/site.git', false],
    'forced SSH' => ['ssh://git@example.test:notaport/acme/site.git', true],
    'normal HTTPS with a six-digit port' => ['https://example.test:000022/acme/site.git', false],
    'forced SSH with a six-digit port' => ['ssh://git@example.test:000022/acme/site.git', true],
    'forced HTTPS with an out-of-range port' => ['https://example.test:65536/acme/site.git', true],
    'normal SSH with an out-of-range port' => ['ssh://git@example.test:65536/acme/site.git', false],
]);

it('rejects origins with a trailing line feed during inspection', function (string $origin, bool $force): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'remote',
        'set-url',
        'origin',
        "{$origin}\n",
    ]);

    expect(fn () => $this->removal->inspect($instance, $force))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_origin_mismatch');
        });
    expect(is_dir($instance->checkout_path))->toBeTrue();
})->with([
    'normal HTTPS' => ['https://example.test/acme/site.git', false],
    'forced HTTPS' => ['https://example.test/acme/site.git', true],
    'normal SSH' => ['ssh://git@example.test/acme/site.git', false],
    'forced SSH' => ['ssh://git@example.test/acme/site.git', true],
]);

it('refuses origins with a trailing line feed at the destructive boundary', function (
    string $origin,
    bool $force,
): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $inventory = $this->removal->inspect($instance, $force);
    orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'remote',
        'set-url',
        'origin',
        "{$origin}\n",
    ]);

    expect(fn () => $this->removal->remove($instance, $inventory, $force))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_origin_mismatch');
        });
    expect(is_dir($instance->checkout_path))->toBeTrue();
})->with([
    'normal HTTPS' => ['https://example.test/acme/site.git', false],
    'forced HTTPS' => ['https://example.test/acme/site.git', true],
    'normal SSH' => ['ssh://git@example.test/acme/site.git', false],
    'forced SSH' => ['ssh://git@example.test/acme/site.git', true],
]);

it('finalizes one recorded checkout with durable matching evidence', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'recorded');
    $member = orb180_record_source($this->removal, $instance, false);
    $receipt = hash(
        'sha256',
        "{$member->instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
    );

    expect($this->removal->finalize($member))
        ->toBe($receipt)
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and(is_dir(dirname($instance->checkout_path)))
        ->toBeFalse()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue()
        ->and($this->removal->revalidate($member))
        ->toBe(InstanceSourceRevalidationState::Completed)
        ->and($this->removal->finalize($member))
        ->toBe($receipt);
    expect(file_get_contents(orb180_receipt_path($member)))
        ->toBe("{$receipt}\n");
});

it('removes an empty App slug parent after forced finalization of the last checkout', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'last');
    $member = orb180_record_source($this->removal, $instance, true);
    $grouping = dirname($instance->checkout_path);

    expect($this->removal->finalize($member))
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and(is_dir($grouping))
        ->toBeFalse()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue();
});

it('removes a checkout and its directory named after the slug before a Project slug change', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'renamed');
    $grouping = dirname($instance->checkout_path);
    $this->orbitApp->update(['slug' => 'renamed-app']);
    $instance->refresh();
    $member = orb180_record_source($this->removal, $instance, true);

    expect(basename($grouping))
        ->not->toBe('renamed-app')
        ->and($this->removal->finalize($member))
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and(is_dir($grouping))
        ->toBeFalse()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue();
});

it('retains a Project slug parent that still holds another Orbit checkout', function (): void {
    $first = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'one');
    $second = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'two');
    $member = orb180_record_source($this->removal, $first, true);
    $grouping = dirname($first->checkout_path);

    expect($this->removal->finalize($member))
        ->toBeString()
        ->and(file_exists($first->checkout_path))
        ->toBeFalse()
        ->and(is_dir($second->checkout_path))
        ->toBeTrue()
        ->and(is_dir($grouping))
        ->toBeTrue()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue();
});

it('retains a Project slug parent that still holds unrelated entries', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'only');
    $member = orb180_record_source($this->removal, $instance, true);
    $grouping = dirname($instance->checkout_path);
    file_put_contents($grouping.'/notes.txt', "keep\n");

    expect($this->removal->finalize($member))
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and(is_dir($grouping))
        ->toBeTrue()
        ->and(is_file($grouping.'/notes.txt'))
        ->toBeTrue()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue();
});

it('removes a detached checkout with forced nullable branch evidence', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'detached');
    orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '--detach']);
    $instance->update(['branch' => null]);

    $inventory = orb178_remove_source($this->removal, $instance->refresh()->load(['project', 'node']), true);

    expect($inventory->branch)
        ->toBeNull()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and(is_dir(dirname($instance->checkout_path)))
        ->toBeFalse()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue();
});

it('finalizes a detached checkout from durable nullable branch evidence', function (): void {
    $instance = orb180_resolved_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'recorded-detached',
    );
    orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '--detach']);
    $instance->update(['branch' => null]);
    $member = orb180_record_source($this->removal, $instance->refresh()->load(['project', 'node']), false);

    $receipt = $this->removal->finalize($member);

    expect($member->branch)
        ->toBeNull()
        ->and($receipt)
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and($this->removal->revalidate($member))
        ->toBe(InstanceSourceRevalidationState::Completed);
});

it('finalizes newer published and forced unpublished commits from immutable evidence', function (
    bool $force,
): void {
    $instance = orb180_resolved_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        $force ? 'unpublished-head' : 'published-head',
    );
    $historicalCommit = $instance->starting_commit;
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.name', 'Orbit Test']);
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.email', 'orbit@example.test']);
    file_put_contents($instance->checkout_path.'/newer.txt', $force ? 'unpublished' : 'published');
    orb76_run(['git', '-C', $instance->checkout_path, 'add', 'newer.txt']);
    orb76_run(['git', '-C', $instance->checkout_path, 'commit', '-m', 'Advance source']);
    $observedCommit = trim(orb76_run(['git', '-C', $instance->checkout_path, 'rev-parse', 'HEAD'])->stdout);

    if (! $force) {
        orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'push',
            'origin',
            "HEAD:refs/heads/{$instance->branch}",
        ]);
    }

    $member = orb180_record_source($this->removal, $instance, $force);

    expect($member->starting_commit)
        ->toBe($historicalCommit)
        ->and($member->source_commit)
        ->toBe($observedCommit)
        ->and($member->source_commit)
        ->not
        ->toBe($member->starting_commit)
        ->and($this->removal->finalize($member))
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse();
})->with([
    'normal removal at a newer published HEAD' => false,
    'forced removal at an unpublished HEAD' => true,
]);

it('refuses normal finalization when the observed commit is no longer published', function (): void {
    $instance = orb180_resolved_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'withdrawn-observed-head',
    );
    $historicalCommit = $instance->starting_commit;
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.name', 'Orbit Test']);
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.email', 'orbit@example.test']);
    file_put_contents($instance->checkout_path.'/newer.txt', 'published then withdrawn');
    orb76_run(['git', '-C', $instance->checkout_path, 'add', 'newer.txt']);
    orb76_run(['git', '-C', $instance->checkout_path, 'commit', '-m', 'Advance source']);
    orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'push',
        'origin',
        "HEAD:refs/heads/{$instance->branch}",
    ]);
    $member = orb180_record_source($this->removal, $instance, false);
    $observedCommit = $member->source_commit;
    $repository = $this->repository;
    $branch = $instance->branch;
    $this->transport->beforeFinalization = static function () use ($repository, $branch, $historicalCommit): void {
        orb76_run([
            'git',
            "--git-dir={$repository}",
            'update-ref',
            "refs/heads/{$branch}",
            $historicalCommit,
        ]);
    };

    expect($observedCommit)
        ->not
        ->toBe($historicalCommit)
        ->and(fn () => $this->removal->finalize($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
});

it('converges forced linked removal beside independently changing siblings', function (string $change, bool $quarantined): void {
    [$checkout, $worktree, $sibling] = orb180_worktree_source(
        $this->source, $this->orbitApp, $this->node, $this->appsRoot, 'converge',
    );
    $member = orb180_record_source($this->removal, $worktree, true);
    if ($quarantined) {
        orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'move', $worktree->checkout_path, orb180_quarantine_path($member)]);
    }
    $siblingAdmin = trim(orb76_run(['git', '-C', $sibling, 'rev-parse', '--absolute-git-dir'])->stdout);
    if ($change === 'added') {
        $sibling = $this->appsRoot.'/acme/new-sibling';
        orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'new-sibling', $sibling, 'HEAD']);
    } elseif ($change === 'removed') {
        orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'remove', $sibling]);
    } else {
        $sibling = dirname(orb180_quarantine_path($member)).'/independent.999.quarantine';
        orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'move', $this->appsRoot.'/acme/converge-sibling', $sibling]);
    }
    $before = orb866_source_bytes($checkout->checkout_path.'/.git');
    $siblingBefore = is_dir($sibling) ? orb866_source_bytes($sibling) : [];

    expect($this->removal->revalidate($member))->toBe($quarantined
        ? InstanceSourceRevalidationState::Quarantined
        : InstanceSourceRevalidationState::Present);
    $receipt = $this->removal->finalize($member);
    expect($receipt)->not->toBeEmpty()
        ->and($this->removal->revalidate($member))->toBe(InstanceSourceRevalidationState::Completed)
        ->and(is_dir($worktree->checkout_path))->toBeFalse()
        ->and(is_dir($checkout->checkout_path))->toBeTrue()
        ->and(is_dir($sibling))->toBe($change !== 'removed');
    if ($change !== 'removed') {
        expect(orb866_source_bytes($sibling))->toBe($siblingBefore);
    }
    // Removing the target changes only its own administration in the shared Git directory.
    $targetAdmin = $checkout->checkout_path.'/.git/worktrees/converge';
    foreach ($before as $path => $bytes) {
        if (! str_starts_with($path, '/worktrees/converge/')) {
            expect(hash_file('sha256', $checkout->checkout_path.'/.git'.$path))->toBe($bytes);
        }
    }
    expect(is_dir($targetAdmin))->toBeFalse()
        ->and(is_dir($siblingAdmin))->toBe($change !== 'removed');
})->with(['added', 'removed', 'quarantined'])->with(['at original path' => false, 'in quarantine' => true]);

it('refuses real source drift during forced linked removal beside siblings', function (string $change): void {
    [$checkout, $worktree, $sibling] = orb180_worktree_source(
        $this->source, $this->orbitApp, $this->node, $this->appsRoot, 'drift',
    );
    $member = orb180_record_source($this->removal, $worktree, true);
    if ($change === 'commit') {
        orb76_run(['git', '-C', $worktree->checkout_path, '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '--allow-empty', '-m', 'changed']);
    } elseif ($change === 'branch') {
        orb76_run(['git', '-C', $worktree->checkout_path, 'checkout', '-b', 'changed']);
    } elseif ($change === 'origin') {
        orb76_run(['git', '-C', $worktree->checkout_path, 'remote', 'set-url', 'origin', 'ssh://git@example.test/foreign/site.git']);
    } else {
        rename($worktree->checkout_path, $worktree->checkout_path.'-old');
        mkdir($worktree->checkout_path);
    }
    $siblingBefore = orb866_source_bytes($sibling);
    expect(fn () => $this->removal->finalize($member))->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($worktree->checkout_path))->toBeTrue()
        ->and(is_dir($checkout->checkout_path))->toBeTrue()
        ->and(orb866_source_bytes($sibling))->toBe($siblingBefore);
})->with(['commit', 'branch', 'origin', 'replacement']);

it('finalizes forced linked removal when a sibling moves after the last inspection', function (): void {
    [$checkout, $worktree, $sibling] = orb180_worktree_source(
        $this->source, $this->orbitApp, $this->node, $this->appsRoot, 'late',
    );
    $member = orb180_record_source($this->removal, $worktree, true);
    $destination = dirname(orb180_quarantine_path($member)).'/independent.999.quarantine';
    $this->transport->beforeFinalization = static function () use ($checkout, $sibling, $destination): void {
        orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'move', $sibling, $destination]);
    };
    expect($this->removal->finalize($member))->not->toBeEmpty()
        ->and(is_dir($destination))->toBeTrue()
        ->and(is_dir($worktree->checkout_path))->toBeFalse();
});

it('converges forced linked removal after its acknowledged quarantine is lost beside siblings', function (): void {
    [$checkout, $worktree, $sibling] = orb180_worktree_source(
        $this->source, $this->orbitApp, $this->node, $this->appsRoot, 'lost',
    );
    $member = orb180_record_source($this->removal, $worktree, true);
    [$quarantine, $admin, $receipt] = orb180_stage_worktree_receipt($member);
    $this->files->deleteDirectory($quarantine);
    $siblingBefore = orb866_source_bytes($sibling);
    expect($this->removal->revalidate($member))->toBe(InstanceSourceRevalidationState::ReceiptPendingCleanup)
        ->and($this->removal->finalize($member))->toBe($receipt)
        ->and($this->removal->revalidate($member))->toBe(InstanceSourceRevalidationState::Completed)
        ->and(is_dir($admin))->toBeFalse()
        ->and(is_dir($checkout->checkout_path))->toBeTrue()
        ->and(orb866_source_bytes($sibling))->toBe($siblingBefore);
});

it('finalizes one recorded worktree while preserving shared Git state', function (): void {
    $checkout = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'shared');
    $worktreePath = $this->appsRoot.'/acme/feature';
    $siblingPath = $this->appsRoot.'/acme/sibling';
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'feature', $worktreePath, 'HEAD']);
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'sibling', $siblingPath, 'HEAD']);
    $worktree = Instance::query()
        ->create([
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'feature',
            'source_layout' => 'worktree',
            'checkout_path' => $worktreePath,
            'branch' => 'feature',
            'starting_commit' => $checkout->starting_commit,
            'status' => InstanceState::SourceResolved,
        ])
        ->load(['project', 'node']);
    $remoteBranches = orb76_run([
        'git',
        '--git-dir='.$this->repository,
        'for-each-ref',
        '--format=%(refname)',
        'refs/heads',
    ])->stdout;
    $member = orb180_record_source($this->removal, $worktree, false);

    $this->removal->finalize($member);

    $worktrees = orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'list', '--porcelain'])->stdout;
    expect(file_exists($worktreePath))
        ->toBeFalse()
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(is_dir($siblingPath))
        ->toBeTrue()
        ->and(is_dir(dirname($worktreePath)))
        ->toBeTrue()
        ->and($worktrees)
        ->toContain("worktree {$checkout->checkout_path}")
        ->toContain("worktree {$siblingPath}")
        ->not
        ->toContain("worktree {$worktreePath}")
        ->and(
            orb76_run([
                'git',
                '-C',
                $checkout->checkout_path,
                'show-ref',
                '--verify',
                'refs/heads/feature',
            ])->succeeded(),
        )
        ->toBeTrue()
        ->and(orb76_run([
            'git',
            '--git-dir='.$this->repository,
            'for-each-ref',
            '--format=%(refname)',
            'refs/heads',
        ])->stdout)
        ->toBe($remoteBranches);
});

it('finalizes a recorded fixed set against each expected real Git inventory', function (): void {
    [$checkout, $first, $second] = orb182_real_source_graph(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'cascade',
    );
    $members = orb182_record_sources($this->removal, [$first, $second, $checkout], true);
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $remoteBranches = orb76_run([
        'git',
        '--git-dir='.$this->repository,
        'for-each-ref',
        '--format=%(refname)',
        'refs/heads',
    ])->stdout;

    $expectation = new InstanceSourceRevalidationExpectation($paths, $paths);
    expect($this->removal->revalidate($members[2], $expectation))
        ->toBe(InstanceSourceRevalidationState::Present);
    $this->removal->prepare($members[0], $expectation);
    $members[0]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[0]);
    $members[0]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $firstReceipt = $this->removal->finalize($members[0], $expectation);
    $members[0]->update(['source_finalized_at' => now(), 'finalization_receipt' => $firstReceipt]);
    $afterFirst = [$checkout->checkout_path, $second->checkout_path];
    sort($afterFirst, SORT_STRING);
    $expectation = new InstanceSourceRevalidationExpectation($afterFirst, $afterFirst);
    $this->removal->prepare($members[1], $expectation);
    $members[1]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[1]);
    $members[1]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);

    expect($this->removal->revalidate($members[1], $expectation))
        ->toBe(InstanceSourceRevalidationState::Present);
    $secondReceipt = $this->removal->finalize($members[1], $expectation);
    $members[1]->update(['source_finalized_at' => now(), 'finalization_receipt' => $secondReceipt]);
    $expectation = new InstanceSourceRevalidationExpectation(
        [$checkout->checkout_path],
        [$checkout->checkout_path],
    );
    $this->removal->prepare($members[2], $expectation);
    $members[2]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[2]);
    $members[2]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);

    expect($this->removal->revalidate($members[2], $expectation))
        ->toBe(InstanceSourceRevalidationState::Present)
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(file_exists($first->checkout_path))
        ->toBeFalse()
        ->and(file_exists($second->checkout_path))
        ->toBeFalse();

    $this->removal->finalize($members[2], $expectation);

    expect(file_exists($checkout->checkout_path))
        ->toBeFalse()
        ->and(is_dir(dirname($checkout->checkout_path)))
        ->toBeFalse()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue()
        ->and($this->removal->revalidate($members[0], $expectation))
        ->toBe(InstanceSourceRevalidationState::Completed)
        ->and(orb76_run([
            'git',
            '--git-dir='.$this->repository,
            'for-each-ref',
            '--format=%(refname)',
            'refs/heads',
        ])->stdout)
        ->toBe($remoteBranches);
});

it('refuses an unknown real worktree after one accepted member completes', function (): void {
    [$checkout, $first, $second] = orb182_real_source_graph(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'unknown',
    );
    $members = orb182_record_sources($this->removal, [$first, $second, $checkout], true);
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->removal->prepare(
        $members[0],
        new InstanceSourceRevalidationExpectation($paths, $paths),
    );
    $members[0]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[0]);
    $members[0]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $receipt = $this->removal->finalize(
        $members[0],
        new InstanceSourceRevalidationExpectation($paths, $paths),
    );
    $members[0]->update(['source_finalized_at' => now(), 'finalization_receipt' => $receipt]);
    $unknown = $this->appsRoot.'/acme/unknown-late';
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'unknown-late', $unknown, 'HEAD']);
    $expected = [$checkout->checkout_path, $second->checkout_path];
    sort($expected, SORT_STRING);

    expect(fn () => $this->removal->revalidate(
        $members[1],
        new InstanceSourceRevalidationExpectation($expected, $expected),
    ))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($second->checkout_path))
        ->toBeTrue()
        ->and(is_dir($unknown))
        ->toBeTrue()
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue();
});

it('refuses an independent replacement at a completed member path', function (): void {
    [$checkout, $first, $second] = orb182_real_source_graph(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'completed-replacement',
    );
    $members = orb182_record_sources($this->removal, [$first, $second, $checkout], true);
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $expectation = new InstanceSourceRevalidationExpectation($paths, $paths);
    $this->removal->prepare($members[0], $expectation);
    $members[0]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[0]);
    $members[0]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $receipt = $this->removal->finalize($members[0], $expectation);
    $members[0]->update([
        'source_finalized_at' => now(),
        'finalization_receipt' => $receipt,
    ]);
    $first->delete();
    $members[0]->update(['runtime_cleaned_at' => now(), 'row_deleted_at' => now()]);
    orb76_run(['git', 'clone', $this->repository, $first->checkout_path]);
    $expected = [$checkout->checkout_path, $second->checkout_path];
    sort($expected, SORT_STRING);

    expect(fn () => $this->removal->revalidate(
        $members[0]->refresh(),
        new InstanceSourceRevalidationExpectation($expected, $expected),
    ))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($first->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(is_dir($second->checkout_path))
        ->toBeTrue()
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue();
});

it('authenticates a completed worktree shrink before the database checkpoint', function (): void {
    [$checkout, $first, $second] = orb182_real_source_graph(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'receipt-cascade',
    );
    $members = orb182_record_sources($this->removal, [$first, $second, $checkout], true);
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->removal->prepare(
        $members[0],
        new InstanceSourceRevalidationExpectation($paths, $paths),
    );
    $members[0]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[0]);
    $members[0]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $this->removal->finalize(
        $members[0],
        new InstanceSourceRevalidationExpectation($paths, $paths),
    );
    $expected = [$checkout->checkout_path, $second->checkout_path];
    sort($expected, SORT_STRING);
    $states = [$members[0]->id => InstanceSourceRevalidationState::Completed];
    $expectation = new InstanceSourceRevalidationExpectation($expected, $expected, $states);

    expect($members[0]->refresh()->source_finalized_at)
        ->toBeNull()
        ->and($this->removal->revalidate($members[0], $expectation))
        ->toBe(InstanceSourceRevalidationState::Completed)
        ->and($this->removal->revalidate($members[1], $expectation))
        ->toBe(InstanceSourceRevalidationState::Present)
        ->and(is_dir($second->checkout_path))
        ->toBeTrue()
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue();

    $this->removal->prepare($members[1], $expectation);
    $members[1]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[1]);
    $members[1]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $receipt = $this->removal->finalize($members[1], $expectation);
    $members[1]->update(['source_finalized_at' => now(), 'finalization_receipt' => $receipt]);
    $rootOnly = [$checkout->checkout_path];
    $states[$members[1]->id] = InstanceSourceRevalidationState::Completed;

    expect($this->removal->revalidate(
        $members[0]->refresh(),
        new InstanceSourceRevalidationExpectation($rootOnly, $rootOnly, $states),
    ))
        ->toBe(InstanceSourceRevalidationState::Completed)
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(is_dir($second->checkout_path))
        ->toBeFalse();
});

it('refuses to finalize a checkout while a linked worktree depends on it', function (): void {
    $checkout = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'linked-main');
    $siblingPath = $this->appsRoot.'/acme/linked-sibling';
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'linked-sibling', $siblingPath, 'HEAD']);

    expect(fn () => orb180_record_source($this->removal, $checkout, true))
        ->toThrow(RuntimeConvergenceException::class);
    expect(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(is_dir($siblingPath))
        ->toBeTrue()
        ->and(orb76_run(['git', '-C', $checkout->checkout_path, 'status', '--porcelain'])->succeeded())
        ->toBeTrue()
        ->and(orb76_run(['git', '-C', $siblingPath, 'status', '--porcelain'])->succeeded())
        ->toBeTrue();
});

it('resumes matching quarantine before and after receipt creation', function (bool $withReceipt): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'recovery');
    $member = orb180_record_source($this->removal, $instance, false);
    $quarantine = orb180_quarantine_path($member);
    expect(rename($instance->checkout_path, $quarantine))->toBeTrue();
    $receipt = hash(
        'sha256',
        "{$member->instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
    );

    if ($withReceipt) {
        file_put_contents(orb180_receipt_path($member), "{$receipt}\n");
    }

    expect($this->removal->revalidate($member))
        ->toBe(
            $withReceipt
                ? InstanceSourceRevalidationState::ReceiptPendingCleanup
                : InstanceSourceRevalidationState::Quarantined,
        )
        ->and($this->removal->finalize($member))
        ->toBe($receipt)
        ->and(file_exists($quarantine))
        ->toBeFalse();
})->with([
    'before receipt' => false,
    'after receipt' => true,
]);

it('cleans an acknowledged checkout after its Git directory was partially deleted', function (): void {
    $instance = orb180_resolved_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'partial-checkout',
    );
    $member = orb180_record_source($this->removal, $instance, true);
    $quarantine = orb180_quarantine_path($member);
    expect(rename($instance->checkout_path, $quarantine))->toBeTrue();
    $receipt = orb180_write_receipt($member);
    expect($this->files->deleteDirectory("{$quarantine}/.git"))->toBeTrue();

    expect($this->removal->revalidate($member))
        ->toBe(InstanceSourceRevalidationState::ReceiptPendingCleanup)
        ->and($this->removal->finalize($member))
        ->toBe($receipt)
        ->and(file_exists($quarantine))
        ->toBeFalse()
        ->and(is_dir(dirname($instance->checkout_path)))
        ->toBeFalse()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue();
});

it('cleans an acknowledged worktree after one Git structure was partially deleted', function (string $fault): void {
    [$checkout, $worktree, $siblingPath] = orb180_worktree_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        "partial-{$fault}",
    );
    $member = orb180_record_source($this->removal, $worktree, true);
    [$quarantine, $admin, $receipt] = orb180_stage_worktree_receipt($member);

    match ($fault) {
        'git-file' => unlink("{$quarantine}/.git"),
        'admin-entry' => $this->files->deleteDirectory($admin),
        'quarantine' => $this->files->deleteDirectory($quarantine),
        'admin-gitdir' => unlink("{$admin}/gitdir"),
    };

    expect($this->removal->revalidate($member))
        ->toBe(InstanceSourceRevalidationState::ReceiptPendingCleanup)
        ->and($this->removal->finalize($member))
        ->toBe($receipt)
        ->and(file_exists($quarantine))
        ->toBeFalse()
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(is_dir($siblingPath))
        ->toBeTrue()
        ->and(orb76_run(['git', '-C', $siblingPath, 'status', '--porcelain'])->succeeded())
        ->toBeTrue()
        ->and(
            orb76_run([
                'git',
                '-C',
                $checkout->checkout_path,
                'show-ref',
                '--verify',
                "refs/heads/partial-{$fault}",
            ])->succeeded(),
        )
        ->toBeTrue();
})->with(['git-file', 'admin-entry', 'quarantine', 'admin-gitdir']);

it('refuses changed or ambiguous worktree administration during receipt recovery', function (string $fault): void {
    [, $worktree, $siblingPath] = orb180_worktree_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        "metadata-{$fault}",
    );
    $member = orb180_record_source($this->removal, $worktree, true);
    [$quarantine, $admin] = orb180_stage_worktree_receipt($member);

    match ($fault) {
        'changed' => file_put_contents("{$admin}/gitdir", "{$siblingPath}/.git\n"),
        'ambiguous' => (function () use ($admin): void {
            expect($this->files->copyDirectory($admin, dirname($admin).'/duplicate'))->toBeTrue();
        })(),
    };

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($quarantine))
        ->toBeTrue()
        ->and(is_dir($siblingPath))
        ->toBeTrue();
})->with(['changed', 'ambiguous']);

it('refuses a replaced quarantine after writing the completion receipt', function (): void {
    $instance = orb180_resolved_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'replaced-quarantine',
    );
    $member = orb180_record_source($this->removal, $instance, true);
    $quarantine = orb180_quarantine_path($member);
    $preserved = $this->sandbox.'/preserved-quarantine';
    expect(rename($instance->checkout_path, $quarantine))->toBeTrue();
    orb180_write_receipt($member);
    expect(rename($quarantine, $preserved))->toBeTrue();
    expect(mkdir($quarantine))->toBeTrue();

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($preserved.'/.git'))
        ->toBeTrue()
        ->and(is_dir($quarantine))
        ->toBeTrue();
});

it('refuses missing, ambiguous, or mismatched recovery evidence', function (string $fault): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'conflict');
    $member = orb180_record_source($this->removal, $instance, true);
    $preserved = $this->sandbox.'/preserved';

    match ($fault) {
        'missing' => rename($instance->checkout_path, $preserved),
        'ambiguous' => (function () use ($instance, $member): void {
            expect(rename($instance->checkout_path, orb180_quarantine_path($member)))->toBeTrue();
            mkdir($instance->checkout_path);
        })(),
        'journal' => file_put_contents(
            dirname(orb180_receipt_path($member))."/{$member->instance_removal_id}.{$member->id}.journal",
            "mismatched\n",
        ),
    };

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(
            file_exists($preserved)
            || file_exists($instance->checkout_path)
            || file_exists(orb180_quarantine_path($member)),
        )
        ->toBeTrue();
})->with(['missing', 'ambiguous', 'journal']);

it('refuses changed recorded source identity before further deletion', function (string $fault): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'changed');
    $member = orb180_record_source($this->removal, $instance, true);
    $preserved = $this->sandbox.'/preserved';

    match ($fault) {
        'physical' => (function () use ($instance, $preserved): void {
            expect(rename($instance->checkout_path, $preserved))->toBeTrue();
            orb76_run([
                'git',
                'clone',
                '--no-checkout',
                '--origin',
                'origin',
                '--',
                $this->repository,
                $instance->checkout_path,
            ]);
            orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '-b', 'changed', $instance->starting_commit]);
        })(),
        'repository' => orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'remote',
            'set-url',
            'origin',
            'https://example.test/other/repository.git',
        ]),
        'layout' => $instance->update(['source_layout' => 'worktree']),
        'placement' => $instance->update(['checkout_path' => $this->appsRoot.'/acme/replacement']),
        'canonical repository' => $instance
            ->project
            ->forceFill([
                'repository_url' => 'https://example.test/other/repository.git',
                'repository_identity' => 'example.test/other/repository',
            ])
            ->save(),
        'physical layout' => orb180_share_git_directory($instance, $this->sandbox),
        'branch' => orb76_run(['git', '-C', $instance->checkout_path, 'branch', '-m', 'changed-branch']),
        'ancestry' => orb180_replace_ancestry($instance),
        'inventory' => orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'worktree',
            'add',
            '-b',
            'late',
            $this->appsRoot.'/acme/late',
            'HEAD',
        ]),
    };

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(file_exists((string) $member->checkout_path))
        ->toBeTrue();
})->with([
    'physical',
    'repository',
    'layout',
    'placement',
    'canonical repository',
    'physical layout',
    'branch',
    'ancestry',
    'inventory',
]);

it('refuses mismatched durable completion evidence', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'receipt');
    $member = orb180_record_source($this->removal, $instance, true);
    $this->removal->finalize($member);
    file_put_contents(orb180_receipt_path($member), "mismatched\n");

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class);
});

it('accepts a canonical-equivalent origin between retries', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'equivalent');
    $member = orb180_record_source($this->removal, $instance, true);
    $this->transport->remoteOrigin = 'ssh://git@EXAMPLE.TEST:22/acme/site.git/';

    expect($this->removal->revalidate($member))
        ->toBe(InstanceSourceRevalidationState::Present)
        ->and($this->removal->finalize($member))
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse();
});

it('refuses newly dirty normal worktree source before moving it', function (): void {
    [$checkout, $instance] = orb182_real_source_graph($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'normal-refusal');
    $member = orb180_record_source($this->removal, $instance, false);
    $worktrees = orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'list', '--porcelain'])->stdout;
    $this->transport->beforeFinalization = static function () use ($instance): void {
        file_put_contents($instance->checkout_path.'/unsafe.txt', 'keep this work');
    };

    expect(fn () => $this->removal->finalize($member))->toThrow(RuntimeConvergenceException::class);

    expect(is_dir($instance->checkout_path))->toBeTrue()
        ->and(file_exists(orb180_quarantine_path($member)))->toBeFalse()
        ->and(file_get_contents($instance->checkout_path.'/unsafe.txt'))->toBe('keep this work')
        ->and(orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'list', '--porcelain'])->stdout)->toBe($worktrees);
});

it('lets force finish an interrupted normal removal of a quarantined linked worktree', function (): void {
    [$checkout, $instance, $sibling] = orb182_real_source_graph($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'force-takeover');
    $member = orb180_record_source($this->removal, $instance, false);
    $runtimeCleanupIds = [];
    $action = orb895_native_removal_action(
        $this->removal,
        $this->sourceLock,
        $this->sandbox.'/removal-locks',
        cleanupRuntime: static function (InstanceRemovalMember $removed) use (&$runtimeCleanupIds): void {
            $runtimeCleanupIds[] = $removed->instance_id;
        },
    );
    $this->transport->interruptAfterSourceMove = true;

    expect(fn () => $action->execute($instance->refresh(), false, runTeardown: false))
        ->toThrow(InstanceRemovalException::class);
    $quarantine = orb180_quarantine_path($member);
    expect(is_dir($quarantine))->toBeTrue()->and(is_dir($instance->checkout_path))->toBeFalse();
    expect($member->refresh()->removal->failed_step)->toBe(InstanceRemovalStep::SourceFinalization)
        ->and($member->runtime_published)->toBeTrue()
        ->and($runtimeCleanupIds)->toBe([]);
    file_put_contents($quarantine.'/unfinished-work', 'dirty quarantined source');

    $completed = $action->execute($instance->refresh(), true, runTeardown: false);

    expect($completed->id)->toBe($member->instance_removal_id)
        ->and($completed->force)->toBeTrue()
        ->and($completed->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($runtimeCleanupIds)->toBe([$instance->id])
        ->and(file_exists($quarantine))->toBeFalse()
        ->and(Instance::query()->whereKey($instance->id)->exists())->toBeFalse()
        ->and(is_dir($checkout->checkout_path.'/.git'))->toBeTrue()
        ->and(is_dir($sibling->checkout_path))->toBeTrue();
    $worktrees = orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'list', '--porcelain'])->stdout;
    expect($worktrees)->not->toContain($quarantine)->not->toContain($instance->checkout_path)
        ->toContain($sibling->checkout_path);
    expect(orb76_run(['git', '-C', $checkout->checkout_path, 'show-ref', '--verify', 'refs/heads/'.$instance->branch])->succeeded())->toBeTrue();
});

it('refuses an immediate forced finalization race', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'race');
    $member = orb180_record_source($this->removal, $instance, true);
    $this->transport->beforeFinalization = static function () use ($instance): void {
        orb76_run(['git', '-C', $instance->checkout_path, 'branch', '-m', 'raced']);
    };

    expect(fn () => $this->removal->finalize($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
});

it('refuses control bytes during recorded recovery and destructive revalidation', function (string $boundary): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'control');
    $member = orb180_record_source($this->removal, $instance, true);
    $mutate = static function () use ($instance): void {
        orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'remote',
            'set-url',
            'origin',
            "ssh://git@example.test/acme/site.git\n",
        ]);
    };

    if ($boundary === 'recovery') {
        $mutate();
    } else {
        $this->transport->beforeFinalization = $mutate;
    }

    $operation = $boundary === 'recovery'
        ? fn () => $this->removal->revalidate($member)
        : fn () => $this->removal->finalize($member);
    expect($operation)
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
})->with(['recovery', 'finalization']);

it('refuses recorded ownership drift', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'ownership');
    $member = orb180_record_source($this->removal, $instance, true);
    $groups = posix_getgroups();
    $alternateGroup = is_array($groups)
        ? collect($groups)->first(static fn (int $group): bool => $group !== posix_getegid())
        : null;

    if (! is_int($alternateGroup)) {
        $this->markTestSkipped('The ownership revalidation test requires a supplementary group.');
    }

    expect(chgrp($instance->checkout_path, $alternateGroup))->toBeTrue();

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
});

it('reports foreign App ownership drift as a removal conflict before path validation', function (): void {
    $instance = orb180_resolved_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'foreign-app',
    );
    $member = orb180_record_source($this->removal, $instance, true);
    $foreign = Project::query()->create([
        'name' => 'Foreign',
        'slug' => 'foreign',
        'repository_url' => 'https://example.test/foreign/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance->update(['project_id' => $foreign->id]);
    $exception = null;

    try {
        $this->removal->revalidate($member);
    } catch (RuntimeConvergenceException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(RuntimeConvergenceException::class)
        ->and($exception?->errorCode)
        ->toBe('instance.removal_conflict')
        ->and(is_dir((string) $member->checkout_path))
        ->toBeTrue();
});

it('holds the per-Node source lock for every recorded adapter call', function (): void {
    $lock = new Orb180RecordingSourceLock;
    $removal = new RemoteDevelopmentInstanceSourceRemoval(
        $this->agents,
        $this->accounts,
        $this->boundary,
        $lock,
        app(RepositoryReadAccess::class),
    );
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'locked');
    $member = orb180_record_source($removal, $instance, true);

    expect($removal->revalidate($member))->toBe(InstanceSourceRevalidationState::Present);
    $removal->inspectRecorded($member, InstanceSourceRevalidationState::Present);
    $removal->finalize($member);

    expect($lock->nodes)
        ->toBe([
            $this->node->id,
            $this->node->id,
            $this->node->id,
            $this->node->id,
            $this->node->id,
        ]);
});

it('removes a clean checkout whose HEAD no longer descends from the recorded starting commit', function (
    bool $force,
    bool $published,
): void {
    orb178_advance_remote($this->sandbox, 'dev');
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    orb285_rewind_head($instance, $published);

    orb178_remove_source($this->removal, $instance, $force);

    expect(file_exists($instance->checkout_path))->toBeFalse();
})->with([
    'normal removal at a published HEAD' => [false, true],
    'forced removal at a published HEAD' => [true, true],
    'forced removal at an unpublished HEAD' => [true, false],
]);

it('refuses normal removal of an unpublished HEAD that no longer descends from the recorded starting commit', function (): void {
    orb178_advance_remote($this->sandbox, 'dev');
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    orb285_rewind_head($instance, published: false);

    expect(fn () => orb178_remove_source($this->removal, $instance, false))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.remove_refused');
        });
    expect(is_dir($instance->checkout_path))->toBeTrue();
});

it('finalizes a forced removal whose HEAD no longer descends from the recorded starting commit', function (): void {
    orb178_advance_remote($this->sandbox, 'dev');
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    orb285_rewind_head($instance, published: false);
    $member = orb180_record_source($this->removal, $instance, true);

    expect($this->removal->finalize($member))
        ->toBeString()
        ->and($member->source_commit)
        ->not
        ->toBe($member->starting_commit)
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse();
});

it('refuses grouping-directory ownership drift before deleting the checkout', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $groups = posix_getgroups();
    $alternateGroup = is_array($groups)
        ? collect($groups)->first(static fn (int $group): bool => $group !== posix_getegid())
        : null;

    if (! is_int($alternateGroup)) {
        $this->markTestSkipped('The ownership-ordering test requires a supplementary group.');
    }

    expect(chgrp(dirname($instance->checkout_path), $alternateGroup))->toBeTrue();

    expect(fn () => orb178_remove_source($this->removal, $instance, true))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_ownership_mismatch');
        });
    expect(is_dir($instance->checkout_path))->toBeTrue();
});

it('refuses a recorded path that is outside the exact App and instance identity', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $instance->update(['checkout_path' => $this->sandbox.'/unrelated']);

    expect(fn () => orb178_remove_source($this->removal, $instance, true))
        ->toThrow(RuntimeConvergenceException::class);
    expect($this->transport->commands)->toBeEmpty();
});

it('fails closed before source resolution when the stored App default branch is incomplete', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $instance->project->default_branch = null;

    expect(fn () => $this->source->resolve($instance))->toThrow(RuntimeConvergenceException::class);
    expect($this->transport->commands)->toBeEmpty();
});

it('fails closed before active removal when stored source identity is incomplete', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $instance->project->update(['type' => 'monorepo']);
    $instance->update(['status' => InstanceState::Active]);

    expect(fn () => orb178_remove_source($this->removal, $instance, true))
        ->toThrow(RuntimeConvergenceException::class);
    expect($this->transport->commands)->toBeEmpty();
});

function orb180_resolved_source(
    RemoteDevelopmentInstanceSourceLifecycle $source,
    Project $project,
    Node $node,
    string $appsRoot,
    string $name,
    ?Task $owner = null,
): Instance {
    $instance = orb76_source_instance($project, $node, $appsRoot, $name, owner: $owner);
    // Finalization tests start with a resolved checkout. Creation, resolution, and task-VM transfer
    // remain real integration cases above; ordinary finalization fixtures only need a private copy.
    if ($owner !== null) {
        $source->prepare($instance, false);
        $resolution = $source->resolve($instance);
        $branch = $resolution->branch;
        $commit = $resolution->startingCommit;
    } else {
        $branch = $name === 'default' ? $project->default_branch : $name;
        orb76_run(['git', 'clone', '--quiet', '--branch', $project->default_branch, test()->repository, $instance->checkout_path]);
        $remoteBranch = 'refs/remotes/origin/'.$branch;
        $exists = new Process(['git', '-C', $instance->checkout_path, 'show-ref', '--verify', '--quiet', $remoteBranch])->run() === 0;
        orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '--quiet', '-B', $branch, $exists ? $remoteBranch : 'refs/remotes/origin/'.$project->default_branch]);
        $commit = trim(orb76_run(['git', '-C', $instance->checkout_path, 'rev-parse', 'HEAD'])->stdout);
        $instance->update(['seed_selected' => true]);
    }
    $instance->update([
        'branch' => $branch,
        'starting_commit' => $commit,
        'status' => InstanceState::SourceResolved,
    ]);

    return $instance->refresh()->load(['project', 'node']);
}

function orb180_record_source(
    RemoteDevelopmentInstanceSourceRemoval $removal,
    Instance $instance,
    bool $force,
    bool $activate = true,
): InstanceRemovalMember {
    $inventory = $removal->inspect($instance, $force);
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'generation_basis_node_id' => $instance->node_id,
        'domain' => "source-finalization-{$instance->id}.test",
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    if ($activate) {
        $route->update(['status' => RouteStatus::Active]);
        $instance->update(['status' => InstanceState::Active]);
    }
    $operation = InstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => $force,
        'inventory_digest' => $inventory->digest,
        'total' => 1,
        'status' => InstanceRemovalStatus::Removing,
        'current_step' => InstanceRemovalStep::SourcePreparation,
    ]);
    $member = $operation
        ->members()
        ->create([
            'position' => 0,
            'instance_id' => $instance->id,
            'project_id' => $instance->project_id,
            'node_id' => $instance->node_id,
            'route_id' => $route->id,
            'name' => $instance->name,
            'environment' => $instance->defaultAppEnv(),
            'runtime_published' => $activate,
            'source_layout' => $inventory->layout,
            'repository_identity' => $inventory->repositoryIdentity,
            'checkout_path' => $inventory->checkoutPath,
            'root' => $instance->effectiveRoot(),
            'branch' => $inventory->branch,
            'starting_commit' => $instance->starting_commit,
            'source_commit' => $inventory->startingCommit,
            'common_repository_path' => $inventory->commonRepositoryPath,
            'source_identity' => $inventory->sourceIdentity,
            'linked_worktree_paths' => $inventory->linkedWorktreePaths,
            'source_digest' => $inventory->digest,
        ]);
    $instance->update(['status' => InstanceState::Removing]);
    $removal->prepare($member);
    $member->update(['source_prepared_at' => now()]);

    return $member->refresh();
}

/**
 * @return array{Instance, Instance, Instance}
 */
function orb182_real_source_graph(
    RemoteDevelopmentInstanceSourceLifecycle $source,
    Project $project,
    Node $node,
    string $appsRoot,
    string $name,
): array {
    $checkout = orb180_resolved_source($source, $project, $node, $appsRoot, "{$name}-main");
    $worktrees = [];

    foreach (["{$name}-a", "{$name}-b"] as $worktreeName) {
        $worktreePath = "{$appsRoot}/acme/{$worktreeName}";
        orb76_run([
            'git',
            '-C',
            $checkout->checkout_path,
            'worktree',
            'add',
            '-b',
            $worktreeName,
            $worktreePath,
            'HEAD',
        ]);
        $worktrees[] = Instance::query()
            ->create([
                'project_id' => $project->id,
                'node_id' => $node->id,
                'name' => $worktreeName,
                'source_layout' => 'worktree',
                'checkout_path' => $worktreePath,
                'branch' => $worktreeName,
                'starting_commit' => $checkout->starting_commit,
                'status' => InstanceState::SourceResolved,
            ])
            ->load(['project', 'node']);
    }

    return [$checkout, $worktrees[0], $worktrees[1]];
}

/**
 * @param  list<Instance>  $instances
 * @return list<InstanceRemovalMember>
 */
function orb182_record_sources(
    RemoteDevelopmentInstanceSourceRemoval $removal,
    array $instances,
    bool $force,
): array {
    $inventories = [];

    foreach ($instances as $instance) {
        $inventories[$instance->id] = $removal->inspect($instance, $force);
    }

    $routes = [];

    foreach ($instances as $instance) {
        $route = Route::query()->create([
            'project_id' => $instance->project_id,
            'node_id' => $instance->node_id,
            'generation_basis_node_id' => $instance->node_id,
            'domain' => "cascade-source-{$instance->id}.test",
            'provenance' => RouteProvenance::Generated,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);
        $instance->update(['status' => InstanceState::Active]);
        $routes[$instance->id] = $route;
    }

    $operation = InstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_instance_id' => $instances[array_key_last($instances)]->id,
        'requested_name' => $instances[array_key_last($instances)]->name,
        'force' => $force,
        'inventory_digest' => hash('sha256', implode('', array_map(
            static fn (InstanceSourceInventory $inventory): string => $inventory->digest,
            $inventories,
        ))),
        'total' => count($instances),
        'status' => InstanceRemovalStatus::Removing,
        'current_step' => InstanceRemovalStep::SourcePreparation,
    ]);
    $members = [];

    foreach ($instances as $position => $instance) {
        $inventory = $inventories[$instance->id];
        $route = $routes[$instance->id];
        $members[] = $operation
            ->members()
            ->create([
                'position' => $position,
                'instance_id' => $instance->id,
                'project_id' => $instance->project_id,
                'node_id' => $instance->node_id,
                'route_id' => $route->id,
                'name' => $instance->name,
                'environment' => $instance->defaultAppEnv(),
                'source_layout' => $inventory->layout,
                'repository_identity' => $inventory->repositoryIdentity,
                'checkout_path' => $inventory->checkoutPath,
                'root' => $instance->effectiveRoot(),
                'branch' => $inventory->branch,
                'starting_commit' => $instance->starting_commit,
                'source_commit' => $inventory->startingCommit,
                'common_repository_path' => $inventory->commonRepositoryPath,
                'source_identity' => $inventory->sourceIdentity,
                'linked_worktree_paths' => $inventory->linkedWorktreePaths,
                'source_digest' => $inventory->digest,
            ]);
    }

    Instance::query()
        ->whereKey(array_map(static fn (Instance $instance): int => $instance->id, $instances))
        ->update(['status' => InstanceState::Removing->value]);

    return array_map(
        static fn (InstanceRemovalMember $member): InstanceRemovalMember => $member->refresh(),
        $members,
    );
}

function orb182_clear_test_route(InstanceRemovalMember $member): void
{
    $route = Route::query()->findOrFail($member->route_id);
    $route->targets()->delete();
    $route->delete();
}

/**
 * @return array{0: Instance, 1: Instance, 2: string}
 */
function orb180_worktree_source(
    RemoteDevelopmentInstanceSourceLifecycle $source,
    Project $project,
    Node $node,
    string $appsRoot,
    string $name,
): array {
    $checkout = orb180_resolved_source($source, $project, $node, $appsRoot, "{$name}-main");
    $worktreePath = "{$appsRoot}/acme/{$name}";
    $siblingPath = "{$appsRoot}/acme/{$name}-sibling";
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', $name, $worktreePath, 'HEAD']);
    orb76_run([
        'git',
        '-C',
        $checkout->checkout_path,
        'worktree',
        'add',
        '-b',
        "{$name}-sibling",
        $siblingPath,
        'HEAD',
    ]);
    $worktree = Instance::query()
        ->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => $name,
            'source_layout' => 'worktree',
            'checkout_path' => $worktreePath,
            'branch' => $name,
            'starting_commit' => $checkout->starting_commit,
            'status' => InstanceState::SourceResolved,
        ])
        ->load(['project', 'node']);

    return [$checkout, $worktree, $siblingPath];
}

/** @return array{0: string, 1: string, 2: string} */
function orb180_stage_worktree_receipt(InstanceRemovalMember $member): array
{
    $quarantine = orb180_quarantine_path($member);
    $commonRepository = (string) $member->common_repository_path;
    orb76_run([
        'git',
        "--git-dir={$commonRepository}/.git",
        'worktree',
        'move',
        (string) $member->checkout_path,
        $quarantine,
    ]);
    $admin = trim(orb76_run(['git', '-C', $quarantine, 'rev-parse', '--absolute-git-dir'])->stdout);
    $worktrees = dirname($admin);
    $recovery = dirname(orb180_receipt_path($member))."/{$member->instance_removal_id}.{$member->id}.recovery";
    file_put_contents(
        $recovery,
        base64_encode($admin)
        ."\n"
        .orb180_file_identity($admin)
        ."\n"
        .orb180_file_identity("{$commonRepository}/.git")
        ."\n"
        .orb180_file_identity($worktrees)
        ."\n",
    );
    chmod($recovery, 0o600);

    return [$quarantine, $admin, orb180_write_receipt($member)];
}

function orb180_write_receipt(InstanceRemovalMember $member): string
{
    $receipt = hash(
        'sha256',
        "{$member->instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
    );
    file_put_contents(orb180_receipt_path($member), "{$receipt}\n");
    chmod(orb180_receipt_path($member), 0o600);

    return $receipt;
}

function orb180_file_identity(string $path): string
{
    $identity = stat($path);
    expect($identity)->toBeArray();

    return "{$identity['dev']}:{$identity['ino']}";
}

function orb180_quarantine_path(InstanceRemovalMember $member): string
{
    return dirname(orb180_receipt_path($member))."/{$member->instance_removal_id}.{$member->id}.quarantine";
}

function orb180_receipt_path(InstanceRemovalMember $member): string
{
    $sourceRoot = dirname(dirname((string) $member->checkout_path));

    return "{$sourceRoot}/.orbit-removals/{$member->instance_removal_id}.{$member->id}.receipt";
}

function orb180_replace_ancestry(Instance $instance): void
{
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.name', 'Orbit Test']);
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.email', 'orbit@example.test']);
    $tree = trim(orb76_run(['git', '-C', $instance->checkout_path, 'rev-parse', 'HEAD^{tree}'])->stdout);
    $commit = trim(orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'commit-tree',
        $tree,
        '-m',
        'Unrelated recorded source',
    ])->stdout);
    orb76_run(['git', '-C', $instance->checkout_path, 'reset', '--hard', $commit]);
}

function orb285_rewind_head(Instance $instance, bool $published): void
{
    if ($published) {
        orb76_run(['git', '-C', $instance->checkout_path, 'reset', '--hard', 'HEAD~1']);
    } else {
        orb180_replace_ancestry($instance);
    }

    expect(orb178_run_allow_failure([
        'git',
        '-C',
        $instance->checkout_path,
        'merge-base',
        '--is-ancestor',
        (string) $instance->starting_commit,
        'HEAD',
    ])->succeeded())
        ->toBeFalse()
        ->and(orb76_run(['git', '-C', $instance->checkout_path, 'status', '--porcelain', '--untracked-files=all'])->stdout)
        ->toBe('');
}

function orb180_share_git_directory(Instance $instance, string $sandbox): void
{
    $shared = $sandbox.'/shared.git';
    expect(new Filesystem()->copyDirectory($instance->checkout_path.'/.git', $shared))->toBeTrue();
    file_put_contents($instance->checkout_path.'/.git/commondir', "{$shared}\n");
}

/**
 * Adds checkout-local insteadOf rules that change the URL `git remote get-url` reports while the
 * configured origin and the repository it reaches stay the same.
 */
function orb76_insteadof_rule(string $checkout, string $sandbox): void
{
    orb76_run(['git', '-C', $checkout, 'config', "url.{$sandbox}/./.insteadOf", "{$sandbox}/"]);
    orb76_run(['git', '-C', $checkout, 'config', 'url.https://rewritten.example.test/.insteadOf', 'ssh://git@example.test/']);
}

function orb178_remove_source(
    RemoteDevelopmentInstanceSourceRemoval $removal,
    Instance $instance,
    bool $force,
): InstanceSourceInventory {
    $inventory = $removal->inspect($instance, $force);
    $removal->remove($instance, $inventory, $force);

    return $inventory;
}

/** @param object{node: Node, appsRoot: string, accounts: ManagedUserAccountResolver, source: RemoteDevelopmentInstanceSourceLifecycle, removal: RemoteDevelopmentInstanceSourceRemoval, sourceLock: NativeAppDevSourceOperationLock} $test */
function orb866_bind_create_sources(object $test): void
{
    $test->node->update(['settings' => ['apps' => ['path' => $test->appsRoot]]]);
    $test->node->roles()->firstOrCreate(['role' => 'app-dev'], ['status' => LifecycleStatus::Active]);
    app()->instance(ManagedUserAccountResolver::class, $test->accounts);
    app()->instance(DevelopmentInstanceSourceLifecycle::class, $test->source);
    app()->instance(AppDevSourceOperationLock::class, $test->sourceLock);
    app()->instance(DevelopmentInstanceSourceRemoval::class, $test->removal);
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $test->removal);
    app()->instance(InstanceRemovalProjector::class, Mockery::mock(InstanceRemovalProjector::class)->shouldIgnoreMissing());
    $provisioner = Mockery::mock(DevelopmentInstanceProvisioner::class);
    $provisioner->shouldReceive('reserve')->andReturnNull();
    $provisioner->shouldReceive('complete')->andThrow(new RuntimeException('Unexpected activation in a failed-prepare test.'));
    app()->instance(DevelopmentInstanceProvisioner::class, $provisioner);
}

/** @return array<string, string> */
function orb866_source_bytes(string $path): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $files[substr($file->getPathname(), strlen($path))] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($files);

    return $files;
}

function orb895_native_removal_action(
    RemoteDevelopmentInstanceSourceRemoval $source,
    AppDevSourceOperationLock $sourceLock,
    string $lockDirectory,
    ?Closure $cleanupRuntime = null,
): DestroyInstanceAction {
    app()->instance(DevelopmentInstanceSourceRemoval::class, $source);
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $source);
    app()->instance(AppDevSourceOperationLock::class, $sourceLock);
    app()->instance(InstanceEnvironmentOperationLock::class, new NativeInstanceEnvironmentOperationLock($lockDirectory, new CommandDeadline));
    app()->instance(InstanceRemovalProjector::class, new class($cleanupRuntime) implements InstanceRemovalProjector
    {
        public function __construct(private readonly ?Closure $cleanup) {}

        public function clearRouteTarget(InstanceRemovalMember $member): string
        {
            Route::query()->findOrFail($member->route_id)->delete();

            return 'deleted';
        }

        public function withdrawPhpPool(InstanceRemovalMember $member): void {}

        public function cleanupRuntime(InstanceRemovalMember $member): void
        {
            if ($this->cleanup !== null) {
                ($this->cleanup)($member);

                return;
            }

            // A routed failed create may need partial runtime cleanup even before activation.
            expect($member->runtime_published)->toBeFalse();
        }
    });

    return app(DestroyInstanceAction::class);
}

function orb76_source_instance(
    Project $project,
    Node $node,
    string $appsRoot,
    string $name,
    ?string $branchOverride = null,
    ?Task $owner = null,
): Instance {
    if (! $node->roles()->where('role', 'app-dev')->exists()) {
        $node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    }

    return Instance::query()
        ->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => $name,
            'source_layout' => 'checkout',
            'checkout_path' => "{$appsRoot}/{$project->slug}/{$name}",
            'branch_override' => $branchOverride,
            'task_workspace_owner_id' => $owner?->id,
            'status' => InstanceState::Reserved,
        ])
        ->load(['project', 'node']);
}

/** The task VM mirror reaches a repository over HTTPS only, so a task VM test names its repository that way. */
function orb76_https_origin(object $test): void
{
    $test->remoteOrigin = 'https://example.test/acme/site.git';
    $test->transport->remoteOrigin = $test->remoteOrigin;
    $test->orbitApp->update(['repository_url' => $test->remoteOrigin]);
}

/** Makes the Node the Node of a live task VM, whose one workspace lives at the fixed checkout path. */
function orb76_task_vm(Node $node): TaskVm
{
    return TaskVm::query()->create(['host_node_id' => $node->id, 'node_id' => $node->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-7',
        'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.200']);
}

/** Use the installed Pest classes, but keep each fixture's imported quality caches private. */
function orb76_cache_dependencies(string $project): void
{
    if (! is_dir($project.'/vendor')) {
        mkdir($project.'/vendor');
        symlink(base_path('vendor/autoload.php'), $project.'/vendor/autoload.php');
    }
}

/** A Pint cache that names the commit it was published for, so a test can tell which ref it came from. */
function orb76_pint_cache(string $sha): string
{
    return json_encode(['php' => PHP_VERSION, 'version' => 'fixture', 'rules' => [], 'hashes' => [$sha => 1]], JSON_THROW_ON_ERROR);
}

/**
 * Publish a quality-only CI cache ref for the work tree's HEAD, in the schema-3 layout that main CI exports.
 *
 * @param  list<string>  $projects
 */
function orb76_publish_ci_cache(string $sandbox, array $projects): string
{
    $work = $sandbox.'/work';
    $sha = trim(orb76_run(['git', '-C', $work, 'rev-parse', 'HEAD'])->stdout);
    $payload = $sandbox.'/payload-'.$sha;
    mkdir($payload);
    foreach ($projects as $project) {
        $directory = $payload.'/'.$project;
        mkdir($directory.'/quality', 0o755, true);
        $pint = orb76_pint_cache($sha);
        file_put_contents($directory.'/quality/pint', $pint);
        file_put_contents($directory.'/manifest.json', json_encode(['schema' => 3, 'project' => $project, 'commit' => $sha,
            'run_id' => '123', 'run_attempt' => '1', 'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'quality' => ['pint' => hash('sha256', $pint)],
            'configurations' => ['pint' => hash_file('sha256', $work.'/'.$project.'/pint.json')],
        ], JSON_THROW_ON_ERROR));
    }
    orb76_run(['git', 'init', $payload]);
    orb76_run(['git', '-C', $payload, 'add', '.']);
    orb76_run(['git', '-C', $payload, '-c', 'user.name=CI', '-c', 'user.email=ci@example.test', 'commit', '-m', 'CI caches']);
    orb76_run(['git', '-C', $payload, 'push', $sandbox.'/origin.git', 'HEAD:refs/orbit/ci-cache/'.$sha]);

    return $sha;
}

function orb76_create_remote_repository(string $sandbox, string $repository): void
{
    $work = $sandbox.'/work';
    orb76_run(['git', 'init', '--initial-branch=main', $work]);
    orb76_run(['git', '-C', $work, 'config', 'user.name', 'Orbit Test']);
    orb76_run(['git', '-C', $work, 'config', 'user.email', 'orbit@example.test']);
    file_put_contents($work.'/README.md', "main\n");
    orb76_run(['git', '-C', $work, 'add', 'README.md']);
    orb76_run(['git', '-C', $work, 'commit', '-m', 'Main']);
    orb76_run(['git', '-C', $work, 'branch', 'dev']);
    orb76_run(['git', 'init', '--bare', $repository]);
    orb76_run(['git', '-C', $work, 'remote', 'add', 'origin', '../origin.git']);
    orb76_run(['git', '-C', $work, 'push', 'origin', 'main', 'dev']);
}

/** @param non-empty-list<string> $arguments */
function orb76_run(array $arguments, ?string $input = null): CommandResult
{
    $process = new Process($arguments, input: $input);
    $process->run();
    $result = new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 0, false);
    expect($result->succeeded())->toBeTrue($result->stderr);

    return $result;
}

/** Start a detached bash executable in $directory, ready and waiting on a FIFO without a child process. */
function orb_checkout_process_spawn(string $directory, string $executable): int
{
    $executable = $executable === 'bash' ? (string) new ExecutableFinder()->find('bash') : $executable;
    $script = <<<'BASH'
        set -eu
        cd -- "$1"
        scratch=$(mktemp -d)
        trap 'rm -rf -- "$scratch"' EXIT
        mkfifo -m 0600 -- "$scratch/wait" "$scratch/ready"
        exec 8<>"$scratch/ready"
        nohup "$2" -c '
            exec 9<>"$0/wait"
            printf "%s\n" "$$" > "$0/ready"
            read -t 300 -u 9 || true
        ' "$scratch" >/dev/null 2>&1 </dev/null &
        child=$!
        # A PID exists before exec. Only the final executable can acknowledge readiness.
        if ! read -r -t 5 -u 8 ready || [ "$ready" != "$child" ]; then
            kill -KILL "$child" || true
            exit 1
        fi
        printf %s "$child"
        BASH;
    $pid = (int) orb76_run(['bash', '-c', $script, '_', $directory, $executable])->stdout;
    expect(orb_checkout_process_running($pid))->toBeTrue()
        ->and(readlink("/proc/{$pid}/exe"))->toBe(realpath($executable))
        ->and(is_link("/proc/{$pid}/fd/9"))->toBeTrue();

    return $pid;
}

function orb_checkout_process_running(int $pid): bool
{
    $stat = @file_get_contents("/proc/{$pid}/stat");

    return is_string($stat) && preg_match('/\) Z /', $stat) !== 1;
}

/** @param non-empty-list<string> $arguments */
function orb178_run_allow_failure(array $arguments): CommandResult
{
    return new NativeProcessRunner()->run(new ProcessInvocation($arguments));
}

function orb178_advance_remote(string $sandbox, string $ref): string
{
    $work = $sandbox.'/work';
    file_put_contents($work.'/README.md', "{$ref}\n", FILE_APPEND);
    orb76_run(['git', '-C', $work, 'add', 'README.md']);
    orb76_run(['git', '-C', $work, 'commit', '-m', "Advance {$ref}"]);
    orb76_run(['git', '-C', $work, 'push', 'origin', "HEAD:refs/heads/{$ref}"]);

    return trim(orb76_run(['git', '-C', $work, 'rev-parse', 'HEAD'])->stdout);
}

final class Orb180RecordingSourceLock implements AppDevSourceOperationLock
{
    /** @var list<int> */
    public array $nodes = [];

    public function synchronized(int $nodeId, Closure $operation): mixed
    {
        $this->nodes[] = $nodeId;

        return $operation();
    }
}

final class Orb76LocalSourceSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public ?Closure $beforeFinalization = null;

    public ?string $prepareFailure = null;

    public bool $interruptAfterSourceMove = false;

    public bool $crossDeviceOnce = false;

    /** @var array<string, string> */
    public array $environment = [];

    public bool $loseFinalizationOnce = false;

    public function __construct(
        public string $remoteOrigin,
        private readonly string $localOrigin,
    ) {}

    public function execute(
        SshConnection $connection,
        RemoteCommand $command,
    ): CommandResult {
        $this->commands[] = $command;
        $agent = LocalAgentBinary::localArguments($command->arguments);
        if ($agent !== null) {
            return $this->agentCommand($agent, (string) stream_get_contents($command->protectedInput?->stream()));
        }
        $input = $command->input;

        $preparing = is_string($input) && str_contains($input, 'allow_existing=$6');
        if ($preparing && $this->prepareFailure === 'before directory') {
            $input = str_replace('mkdir -m 0755 -- "$checkout"', 'exit 75', $input);
        }
        if ($preparing && $this->prepareFailure === 'before receipt') {
            $clone = 'git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false clone --no-checkout --origin origin -- "$repository" "$checkout"';
            $input = str_replace($clone, $clone."\nexit 75", $input);
        }
        $arguments = array_map(
            fn (string $argument): string => $argument === $this->remoteOrigin ? $this->localOrigin : $argument,
            $command->arguments,
        );

        try {
            $executor = new LocalShellSshExecutor(programDirectory: dirname($this->localOrigin));
            $executor->environment = $this->environment;
            $result = $executor->execute($connection, new RemoteCommand($arguments, $input, $command->protectedInput));
        } finally {
            $command->protectedInput?->close();
        }

        return new CommandResult(
            exitCode: $preparing && $this->prepareFailure === 'lost response' && $result->succeeded() ? 255 : $result->exitCode,
            stdout: str_replace(
                [$this->localOrigin, base64_encode($this->localOrigin)],
                [$this->remoteOrigin, base64_encode($this->remoteOrigin)],
                $result->stdout,
            ),
            stderr: $result->stderr,
            durationMs: $result->durationMs,
            truncated: $result->truncated,
        );
    }

    /**
     * Run an agent command with the built binary on this machine, and inject the fault a test asked for at the point
     * where the Node would meet it. Git reaches the Project origin through the local repository.
     *
     * @param  list<string>  $arguments
     */
    private function agentCommand(array $arguments, string $envelope): CommandResult
    {
        $envelope = str_replace(json_encode($this->remoteOrigin, JSON_UNESCAPED_SLASHES), json_encode($this->localOrigin, JSON_UNESCAPED_SLASHES), $envelope);

        if ($arguments[2] === 'instances.finalize-source-removal') {
            $callback = $this->beforeFinalization;
            $this->beforeFinalization = null;
            $callback?->__invoke();

            if ($this->loseFinalizationOnce) {
                $this->loseFinalizationOnce = false;

                return new CommandResult(255, '', 'Connection closed', 1, false);
            }

            if ($this->crossDeviceOnce) {
                $this->crossDeviceOnce = false;

                return new CommandResult(0, json_encode(['ok' => true, 'data' => ['state' => 'cross_device', 'receipt' => null]], JSON_THROW_ON_ERROR)."\n", '', 1, false);
            }

            if ($this->interruptAfterSourceMove) {
                $this->interruptAfterSourceMove = false;
                $input = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR)['input'];
                $quarantine = "{$input['root']}/.orbit-removals/{$input['operation']}.{$input['member']}.quarantine";
                $moved = $input['layout'] === 'worktree'
                    ? orb76_run(['git', '--git-dir='.$input['common_repository'].'/.git', 'worktree', 'move', $input['checkout'], $quarantine])
                    : orb76_run(['mv', '--', $input['checkout'], $quarantine]);
                if (! $moved->succeeded()) {
                    throw new LogicException('The interrupted finalization could not move the source.');
                }

                return new CommandResult(255, '', 'Connection closed', 1, false);
            }
        }

        if ($arguments[2] === 'instances.remove-source') {
            // The checkout names the Project origin, as a Node's does; git reaches it through the local repository.
            $checkout = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR)['input']['checkout'];
            if (is_dir($checkout) && trim(orb76_run(['git', '-C', $checkout, 'config', '--get', 'remote.origin.url'])->stdout) === $this->localOrigin) {
                orb76_run(['git', '-C', $checkout, 'remote', 'set-url', 'origin', $this->remoteOrigin]);
            }
        }

        $process = new Process($arguments, null, LocalAgentBinary::environment(dirname($this->localOrigin), [
            ...$this->environment,
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => "url.{$this->localOrigin}.insteadOf",
            'GIT_CONFIG_VALUE_0' => $this->remoteOrigin,
        ]), $envelope, 120);
        $process->run();
        $stdout = str_replace(
            [json_encode($this->localOrigin, JSON_UNESCAPED_SLASHES), base64_encode($this->localOrigin)],
            [json_encode($this->remoteOrigin, JSON_UNESCAPED_SLASHES), base64_encode($this->remoteOrigin)],
            $process->getOutput(),
        );

        return new CommandResult((int) $process->getExitCode(), $stdout, $process->getErrorOutput(), 1, false);
    }
}
