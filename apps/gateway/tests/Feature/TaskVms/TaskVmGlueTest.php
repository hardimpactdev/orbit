<?php

declare(strict_types=1);

use App\Data\Tasks\TaskGroupData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Compute\SandboxPower;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentThreadStart;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\GitHubTaskBaseBranchFetcher;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\GitRemoteRedirect;
use Tests\Support\StreamingLocalShellSshExecutor;
use Tests\Support\TestOrbitHome;

use function Pest\Laravel\mock;

const TVM_GLUE_PI_TOKEN = 'task-vm-pi-token-with-more-than-32-characters';
const TVM_GLUE_MODEL_KEY = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

function tvm_glue_node(string $name, string $address, string $user = 'orbit'): Node
{
    return Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => $user,
    ]);
}

/** A `vm` group with its workspace on the Node of its task VM, in the given state. */
function tvm_glue_group(TaskVmState $state = TaskVmState::Ready): Task
{
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main']);
    $host = tvm_glue_node('beast', '10.44.0.7', 'nckrtl');
    $node = tvm_glue_node('tvm-1', '10.44.0.130');
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Export', 'brief' => 'Add an export.', 'status' => 'running']);
    TaskVm::query()->create([
        'group_id' => $group->id, 'host_node_id' => $host->id, 'node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-1',
        'state' => $state, 'wireguard_ip' => '10.44.0.130', 'pi_token' => TVM_GLUE_PI_TOKEN, 'model_key' => TVM_GLUE_MODEL_KEY,
    ]);
    $workspace = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id,
        'checkout_path' => '/home/orbit/apps/shop/task-'.$group->id, 'branch' => 'task-'.$group->id, 'status' => 'source_resolved',
    ]);
    $group->taskable()->associate($workspace);
    $group->save();

    return $group;
}

function tvm_glue_workspace(Task $group): Instance
{
    $workspace = $group->fresh()?->taskable;
    assert($workspace instanceof Instance);

    return $workspace;
}

function tvm_glue_ssh(): AppDevFakeSshExecutor
{
    $transport = new AppDevFakeSshExecutor;
    app()->instance(SshExecutor::class, $transport);

    return $transport;
}

beforeEach(function (): void {
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
});

describe('workspace commands', function (): void {
    it('runs on the ready task VM over its normal SSH connection', function (): void {
        $transport = tvm_glue_ssh();

        app(TaskWorkspaceExecutor::class)->execute(tvm_glue_workspace(tvm_glue_group()), new RemoteCommand(['id']), 'proof', 'tasks.proof');

        expect($transport->connections[0]->host)->toBe('10.44.0.130')
            ->and($transport->connections[0]->user)->toBe('orbit')
            ->and($transport->commands[0]->arguments)->toBe(['id']);
    });

    it('refuses a task VM that is not ready', function (TaskVmState $state): void {
        $transport = tvm_glue_ssh();

        expect(fn () => app(TaskWorkspaceExecutor::class)->execute(tvm_glue_workspace(tvm_glue_group($state)), new RemoteCommand(['id']), 'proof', 'tasks.proof'))
            ->toThrow(RuntimeConvergenceException::class, 'has no workspace on its ready task VM');
        expect($transport->commands)->toBe([]);
    })->with([TaskVmState::Provisioning, TaskVmState::Failed, TaskVmState::Destroying]);

    it('refuses a vm group workspace on another Node', function (): void {
        $transport = tvm_glue_ssh();
        $workspace = tvm_glue_workspace(tvm_glue_group());
        $workspace->forceFill(['node_id' => tvm_glue_node('app-dev', '10.44.0.9')->id])->saveQuietly();

        expect(fn () => app(TaskWorkspaceExecutor::class)->execute($workspace->fresh(), new RemoteCommand(['id']), 'proof', 'tasks.proof'))
            ->toThrow(RuntimeConvergenceException::class);
        expect($transport->commands)->toBe([]);
    });
});

describe('publish and fetch', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
        GitHubTestSupport::storeApp();
        Http::fake([
            'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
            'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_push'], 201),
        ]);
    });

    it('fetches and pushes for the ready task VM in the Gateway mirror, and no VM command carries the token', function (): void {
        $root = TestOrbitHome::scratch('tvm-glue');
        mkdir($root, 0700, true);
        $git = static fn (string $directory, string ...$arguments): string => trim((new Process(['git', '-C', $directory, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments]))->mustRun()->getOutput());
        (new Process(['git', 'init', '--quiet', '--bare', '-b', 'main', $root.'/origin.git']))->mustRun();
        (new Process(['git', 'init', '--quiet', '-b', 'main', $root.'/upstream']))->mustRun();
        $git($root.'/upstream', 'commit', '--quiet', '--allow-empty', '-m', 'Start');
        $git($root.'/upstream', 'push', '--quiet', $root.'/origin.git', 'main');
        GitRemoteRedirect::global($root.'/origin.git', 'git@github.com:acme/shop.git');
        (new Process(['git', 'init', '--quiet', $root.'/vm']))->mustRun();
        $git($root.'/vm', 'remote', 'add', 'origin', 'git@github.com:acme/shop.git');
        $group = tvm_glue_group();
        tvm_glue_workspace($group)->forceFill(['checkout_path' => $root.'/vm'])->saveQuietly();
        $group = $group->fresh() ?? $group;
        $transport = new StreamingLocalShellSshExecutor;
        app()->instance(SshExecutor::class, $transport);

        try {
            app(GitHubTaskBaseBranchFetcher::class)->fetch($group, 'main');
            $git($root.'/vm', 'checkout', '--quiet', '-B', 'task-'.$group->id, 'refs/remotes/origin/main');
            $git($root.'/vm', 'commit', '--quiet', '--allow-empty', '-m', 'Export');
            $commit = $git($root.'/vm', 'rev-parse', 'HEAD');
            app(GitHubTaskPullRequestPublisher::class)->push($group, $commit);

            expect($git($root.'/origin.git', 'rev-parse', 'refs/heads/task-'.$group->id))->toBe($commit)
                ->and($transport->sent)->not->toBeEmpty();
            foreach ($transport->sent as [$connection, $command]) {
                $input = $command->protectedInput?->stream();
                expect($connection->host)->toBe('10.44.0.130')
                    ->and(implode("\n", $command->arguments).($command->input ?? '').(is_resource($input) ? stream_get_contents($input) : ''))
                    ->not->toContain('ghs_push')->not->toContain(base64_encode('x-access-token:ghs_push'));
            }
        } finally {
            GitRemoteRedirect::clear();
            TestOrbitHome::clearScratch();
        }
    });

    it('refuses to push or fetch once the task VM is not ready', function (TaskVmState $state): void {
        $transport = tvm_glue_ssh();
        $group = tvm_glue_group($state);

        expect(fn () => app(GitHubTaskPullRequestPublisher::class)->push($group, str_repeat('a', 40)))
            ->toThrow(TaskPullRequestException::class, 'The task workspace is unavailable.')
            ->and(fn () => app(GitHubTaskBaseBranchFetcher::class)->fetch($group, 'main'))
            ->toThrow(TaskPullRequestException::class, 'The base branch could not be fetched.');
        expect($transport->commands)->toBe([]);
    })->with([TaskVmState::Provisioning, TaskVmState::Destroying]);
});

describe('Pi', function (): void {
    it('uses the task VM token and hides its model key', function (): void {
        $node = tvm_glue_workspace(tvm_glue_group())->node;
        $other = tvm_glue_node('app-dev', '10.44.0.9');

        expect(app(PiConnection::class)->token($node))->toBe(TVM_GLUE_PI_TOKEN)
            ->and(app(PiConnection::class)->secrets($node))->toBe([TVM_GLUE_PI_TOKEN, TVM_GLUE_MODEL_KEY])
            ->and(app(PiConnection::class)->baseUrl($node))->toBe('http://10.44.0.130:3774')
            ->and(app(PiConnection::class)->secrets($other))->toBe([])
            ->and(fn () => app(PiConnection::class)->token($other))->toThrow(AgentDriverException::class, 'The Node is no task VM, so it has no Pi server.');
    });

    it('starts a session on the task VM through its only provider', function (): void {
        Http::fake(['http://10.44.0.130:3774/*' => Http::response(['accepted' => true], 202)]);
        $workspace = tvm_glue_workspace(tvm_glue_group());

        app(PiDriver::class)->create(new AgentThreadStart($workspace->node, $workspace, 'Task', 'Do the work', 'gpt-5.6-luna', 'low', TaskThreadRole::Implementer));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.44.0.130:3774/sessions'
            && $request->hasHeader('Authorization', 'Bearer '.TVM_GLUE_PI_TOKEN)
            && $request['model'] === 'orbit-task-vm/gpt-5.6-luna');
    });

    it('reaches a thread on the ready task VM only', function (TaskVmState $state, bool $reached): void {
        Http::fake(['http://10.44.0.130:3774/*' => Http::response(['duplicate' => false], 202)]);
        $group = tvm_glue_group($state);
        $node = tvm_glue_workspace($group)->node;
        $thread = AgentThread::query()->create([
            'task_group_id' => $group->id, 'driver' => 'pi', 'runtime_key' => 'node:'.$node->id, 'external_id' => 'session-1',
            'node_id' => $node->id, 'role' => 'implementer', 'model' => 'gpt-5.6-luna', 'effort' => 'low',
        ]);

        $send = fn () => app(PiDriver::class)->send($thread, 'Continue', 'key-1');

        if ($reached) {
            $send();
            Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.44.0.130:3774/sessions/session-1/messages'
                && $request->hasHeader('Authorization', 'Bearer '.TVM_GLUE_PI_TOKEN));
        } else {
            expect($send)->toThrow(AgentDriverException::class, 'The original task VM Pi server is unavailable.');
            Http::assertNothingSent();
        }
    })->with([[TaskVmState::Ready, true], [TaskVmState::Destroying, false]]);

    it('refuses to start a session on a task VM that is not ready', function (): void {
        Http::fake();
        $workspace = tvm_glue_workspace(tvm_glue_group(TaskVmState::Provisioning));

        expect(fn () => app(PiDriver::class)->create(new AgentThreadStart($workspace->node, $workspace, 'Task', 'Do the work', 'gpt-5.6-luna', 'low', TaskThreadRole::Implementer)))
            ->toThrow(AgentDriverException::class);
        Http::assertNothingSent();
    });
});

describe('task group data', function (): void {
    it('reports the task VM state as sandbox power', function (TaskVmState $state, ?SandboxPower $power): void {
        $group = tvm_glue_group($state);

        expect(TaskGroupData::fromModel($group->fresh())->sandboxPower)->toBe($power);
    })->with([
        [TaskVmState::Provisioning, null],
        [TaskVmState::Ready, SandboxPower::Running],
        [TaskVmState::Failed, null],
        [TaskVmState::Destroyed, SandboxPower::Destroyed],
    ]);
});
