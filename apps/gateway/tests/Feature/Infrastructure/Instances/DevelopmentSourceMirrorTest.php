<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\WorkspaceAgentCommandRunner;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceLifecycle;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\AgentSchemas;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\GitRemoteRedirect;
use Tests\Support\TestOrbitHome;

afterEach(function (): void {
    GitRemoteRedirect::clear();
    TestOrbitHome::clearScratch();
});

it('sends the selected source and cache refs through the Gateway mirror', function (string $mode): void {
    Log::spy();
    $root = TestOrbitHome::scratch('source-mirror');
    mkdir($root, 0o700, true);
    sourceMirrorGit($root, ['init', '--quiet', '-b', 'main', 'upstream']);
    sourceMirrorGit($root.'/upstream', ['commit', '--quiet', '--allow-empty', '-m', 'First']);
    $previous = sourceMirrorGit($root.'/upstream', ['rev-parse', 'HEAD']);
    sourceMirrorGit($root.'/upstream', ['commit', '--quiet', '--allow-empty', '-m', 'Current main']);
    $main = sourceMirrorGit($root.'/upstream', ['rev-parse', 'HEAD']);
    sourceMirrorGit($root, ['clone', '--quiet', '--bare', $root.'/upstream', $root.'/origin.git']);
    GitRemoteRedirect::global($root.'/origin.git', 'https://example.test/site.git');
    $taskVm = ! str_starts_with($mode, 'ordinary');
    $project = Project::query()->create(['name' => 'Site', 'slug' => 'site', 'repository_url' => 'https://example.test/site.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'app-dev', 'platform' => 'linux', 'status' => LifecycleStatus::Active, 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10']);
    $group = $taskVm ? Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Mirror', 'brief' => 'Use the mirror.', 'status' => TaskGroupStatus::Running, 'pr_branch' => $mode === 'incoming' ? 'feature' : null,
    ]) : null;
    if ($taskVm) {
        TaskVm::query()->create(['group_id' => $group?->id, 'node_id' => $node->id, 'host_node_id' => $node->id, 'provider' => 'incus', 'name' => 'mirror-vm', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.130']);
    }
    $name = $taskVm ? 'task-'.$group?->id : 'default';
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'task_workspace_owner_id' => $group?->id,
        'checkout_path' => '/srv/orbit/apps/site/'.$name, 'source_layout' => 'checkout', 'status' => 'reserved',
        'branch_override' => $taskVm ? $name : null, 'source_prepare_id' => 'prepare-mirror',
    ]);
    if ($mode !== 'no-cache-step') {
        ProjectLifecycleStep::query()->create(['project_id' => $project->id, 'phase' => 'setup', 'name' => 'ci-cache', 'command' => '@orbit/ci-caches', 'timeout_seconds' => 60, 'position' => 0]);
    }
    if (in_array($mode, ['ancestor', 'head', 'incoming', 'existing'], true)) {
        sourceMirrorGit($root.'/origin.git', ['update-ref', 'refs/orbit/ci-cache/'.$previous, $previous]);
    }
    if (in_array($mode, ['head', 'ordinary-cached'], true)) {
        sourceMirrorGit($root.'/origin.git', ['update-ref', 'refs/orbit/ci-cache/'.$main, $main]);
    }
    sourceMirrorGit($root.'/origin.git', ['update-ref', 'refs/heads/unrelated', $main]);
    if ($mode === 'existing') {
        sourceMirrorGit($root.'/origin.git', ['update-ref', 'refs/heads/'.$name, $previous]);
    }
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    };
    $runner = new class(app(ProcessRunner::class), $mode === 'fetch-failed') implements ProcessRunner
    {
        public function __construct(private ProcessRunner $runner, private bool $failFetch) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            return $this->failFetch && in_array('+refs/orbit/ci-cache/*:refs/orbit/ci-cache/*', $invocation->arguments, true)
                ? new CommandResult(128, '', 'Cache ref permission denied.', 0, false)
                : $this->runner->run($invocation);
        }
    };
    $workspace = new SourceMirrorAgent;
    if (! $taskVm) {
        $workspace->state = ['haves' => [$main], 'head' => $main, 'branch' => 'main', 'baseline' => null];
    }
    $agents = new FakeAgentCommandRunner;
    $agents->answer('instance.prepare-development-source', ['created' => true])
        ->answer('instance.resolve-development-source', ['branch' => $taskVm ? $name : 'main', 'commit' => $mode === 'existing' ? $previous : $main]);
    $source = new RemoteDevelopmentInstanceSourceLifecycle(
        app(DevelopmentSshExecutor::class), $agents, $accounts, new CheckoutRemovalBoundary(new ProtectedPathCatalog),
        app(RepositoryReadAccess::class), new TaskVmGit($runner, $workspace, $root.'/mirrors', ['https', 'file']),
    );

    $source->prepare($instance, false);
    $source->resolve($instance);

    expect($agents->commands())->toBe(['instance.prepare-development-source', 'instance.resolve-development-source']);
    foreach ($agents->calls as $call) {
        expect($call['input']['network'])->toBe(! $taskVm);
        expect($call['input']['git_read']->isEmpty())->toBeTrue();
        expect($call['input']['git_read']->githubToken())->toBeNull();
    }
    expect($workspace->imports)->toHaveCount($taskVm ? 2 : 1);
    if ($taskVm) {
        expect($workspace->imports[0])->toBe([
            'checkout' => $instance->checkout_path, 'default_branch' => 'main', 'updates' => [
                ['reference' => 'refs/remotes/origin/main', 'commit' => $main],
                ['reference' => 'refs/remotes/origin/'.$name, 'commit' => $mode === 'existing' ? $previous : null],
            ],
        ]);
    }
    $cold = in_array($mode, ['missing', 'fetch-failed'], true);
    $baseline = $mode === 'ancestor' ? $previous : $main;
    $cached = in_array($mode, ['ancestor', 'head', 'ordinary-cached'], true);
    $expected = $cold ? [['reference' => 'refs/worktree/orbit/ci-baseline', 'commit' => null]] : [
        ['reference' => 'refs/orbit/ci-cache/'.$baseline, 'commit' => $cached ? $baseline : null],
        ['reference' => 'refs/worktree/orbit/ci-baseline', 'commit' => $baseline],
    ];
    expect($workspace->imports[array_key_last($workspace->imports)])->toBe([
        'checkout' => $instance->checkout_path, 'default_branch' => 'main', 'updates' => $expected,
    ]);
    if ($cold) {
        Log::shouldHaveReceived('notice')->with('CI caches cold; no cached first-parent ancestor is available in the mirror.', ['project_id' => $project->id, 'tip' => $main])->once();
    } elseif (in_array($mode, ['ancestor', 'head'], true)) {
        Log::shouldHaveReceived('info')->with('CI cache baseline selected.', ['project_id' => $project->id, 'tip' => $main, 'baseline' => $baseline, 'distance' => $mode === 'head' ? 0 : 1])->once();
        $workspace->state = ['haves' => [$main], 'head' => $main, 'branch' => $name, 'baseline' => $baseline];
        $source->resolve($instance);
        expect($workspace->imports[3]['updates'])->toBe($expected);
    } else {
        Log::shouldNotHaveReceived('info');
    }
    if ($mode === 'fetch-failed') {
        Log::shouldHaveReceived('warning')->with('CI cache ref fetch failed; setup continues cold.', ['project_id' => $project->id, 'exit_code' => 128, 'stderr' => 'Cache ref permission denied.'])->once();
    }
})->with(['no-cache-step', 'ancestor', 'head', 'missing', 'fetch-failed', 'existing', 'incoming', 'ordinary-cached', 'ordinary-cold']);

/** @param list<string> $arguments */
function sourceMirrorGit(string $directory, array $arguments): string
{
    return trim(new Process(['git', '-C', $directory, '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', ...$arguments])->mustRun()->getOutput());
}

/** Records requested ref updates; native tests own pack import and checkout mutation. */
final class SourceMirrorAgent implements WorkspaceAgentCommandRunner
{
    /** @var list<array<string, mixed>> */
    public array $imports = [];

    /** @var array<string, mixed> */
    public array $state = ['haves' => [], 'head' => null, 'branch' => null, 'baseline' => null];

    public function runInWorkspace(Node $node, Closure $execute, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
    {
        FakeAgentCommandRunner::admit($node, $command, $input, $output, $binaryInput);
        $result = $this->respond($command, $input, $binaryInput);
        AgentSchemas::assertOutput($command, $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function respond(string $command, array $input, ?AgentBinaryInput $binaryInput): array
    {
        if ($command === 'task-vm.read-git-refs') {
            return $this->state;
        }
        expect($command)->toBe('task-vm.import-git-pack');
        expect($binaryInput)->toBeInstanceOf(AgentBinaryInput::class);
        expect($binaryInput?->bytes)->toBeGreaterThan(0);
        $this->imports[] = $input;

        return ['imported' => true];
    }
}
