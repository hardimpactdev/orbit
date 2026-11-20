<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCommitComparison;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofChangedFile;
use App\Domain\Proof\ProofChangedFiles;
use App\Domain\Proof\ProofCommitRange;
use App\Domain\Proof\ProofCommitSource;
use App\Domain\Proof\ProofCopies;
use App\Domain\Proof\ProofRunIntent;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Proof\ProofSelectionList;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskProvisionIntent;
use App\Domain\Tasks\TaskStatus;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

uses(LazilyRefreshDatabase::class);

/*
 * The task VM lifecycle is removed (docs/reference/old-paths.md): no slot pool, claim, resume or release is left, and every task runs in a
 * task copy. What the VM era left behind still reads and ends: the task groups that ran on a task VM. The task VM tables are dropped.
 */

/** A host of the removed task VMs with `$taskPlaces` places, as Node settings still describe it. */
function vmEraHost(int $taskPlaces = 2, int $ciPlaces = 2): array
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $host = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    config(['proof.project' => $project->id]);

    return [$host, $project];
}

/** A todo task group of the Project. */
function vmEraGroup(Project $project): Task
{
    return Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo]);
}

/** The Gateway, Swamp and VPN Node, and a Swamp dispatch that starts nothing unless `$dispatch` is false. */
function vmEraControlNodes(bool $dispatch = true): void
{
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:gateway', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'gateway-public-key', 'wireguard_public_key' => str_repeat('g', 43).'=']));
    foreach ([RoleName::Gateway, RoleName::Swamp, RoleName::Vpn] as $role) {
        $gateway->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);
    }
    if ($dispatch) {
        app()->instance(SwampDispatch::class, new class
        {
            public function start(Operation $operation): void {}

            public function copy(Closure $push): void {}
        });
    }
}

/** A Swamp socket that records every vault put, so that a test reads what the Gateway wrote for a run. */
function vmEraSocket(): object
{
    $socket = new class implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        private bool $open = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);

            return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }

        /** The value the Gateway put under the vault key. */
        public function put(string $key): string
        {
            foreach ($this->frames as $frame) {
                if ($frame['payload']['key'] === $key) {
                    return $frame['payload']['value'];
                }
            }

            throw new RuntimeException("Nothing was put under [{$key}].");
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    return $socket;
}

/** The control services a proof captures; submission is faked by vmEraControlNodes. */
function vmEraServices(): void
{
    $services = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.3', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:services', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'services-public-key']));
    app(AiRouterState::class)->enable($services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    Operation::query()->forceCreate(['kind' => 'ai_router.task_guard', 'target_type' => 'node', 'target_id' => $services->id, 'node_id' => $services->id,
        'status' => OperationStatus::Succeeded, 'input' => ['intent' => ['state' => 'present']]]);
    vmEraSocket();
    test()->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));
    $home = storage_path('framework/testing/admission-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($home.'/wireguard');
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('h', 32))."\n");
    config(['orbit.home' => $home]);
    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($home));
}

/** A trusted four-batch CI proof on the host fixture, without a real Swamp submission. */
function vmEraCiProofWorld(int $places, bool $casesOnly = false): Node
{
    Queue::fake();
    [$host] = vmEraHost(ciPlaces: $places);
    vmEraControlNodes();
    vmEraServices();
    app(TaskExtensionState::class)->enable();
    config(['proof.execution' => true]);
    $commit = str_repeat('a', 40);
    $source = Mockery::mock(ProofCommitSource::class);
    $source->shouldReceive('commitExists')->andReturn(true);
    $source->shouldReceive('branchesWithHead')->andReturn(['feature']);
    $source->shouldReceive('changes')->andReturn(new ProofChangedFiles($commit, [new ProofChangedFile($casesOnly ? 'swamp/proof/firewall/allow.cases.yaml' : 'changed.php', 'modified')], true));
    $source->shouldReceive('proofPaths')->andReturn(['swamp/proof/firewall/allow.cases.yaml']);
    $source->shouldReceive('caseSelection')->andReturnUsing(static fn (string $base, string $head): array => [
        'version' => 1, 'base' => $base, 'head' => $head, 'files' => [[
            'command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml',
            'only' => $casesOnly ? ['a'] : ['a', 'b', 'c', 'd'],
            'reasons' => $casesOnly ? ['a' => 'changed'] : array_fill_keys(['a', 'b', 'c', 'd'], 'command code changed'),
        ]],
    ]);
    $source->shouldReceive('range')->andReturn(new ProofCommitRange(GitHubComparisonStatus::Identical, new ProofChangedFiles($commit, [], true)));
    $source->shouldReceive('file')->andReturnUsing(static fn (string $sha, string $path): string => match ($path) {
        ProofSelectionList::Path => "changed.php firewall:allow\nswamp/proof/firewall/allow.cases.yaml firewall:allow\n",
        'swamp/proof/batches.yaml' => "batches:\n  one: {}\n  two: {}\n  three: {}\n  four: {}\n",
        default => "command: firewall:allow\ncases:\n  - {id: a, batch: one".($casesOnly ? ', summary: '.$sha : '')."}\n  - {id: b, batch: two}\n  - {id: c, batch: three}\n  - {id: d, batch: four}\n",
    });
    app()->instance(ProofCommitSource::class, $source);
    $host->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);

    return $host;
}

/** The guest Node of a group's old task VM, as the removed slot pool left it. */
function vmEraGuest(): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast-tvm-1', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.133', 'wireguard_ip' => '10.44.0.133', 'user' => 'orbit']));
}

/** A group of the VM era that still holds its workspace Instance on the guest Node of its old VM: it has no task copy. */
function vmEraWorkspaceGroup(TaskGroupStatus $status = TaskGroupStatus::Running): array
{
    [$host, $project] = vmEraHost();
    vmEraControlNodes();
    app(TaskExtensionState::class)->enable();
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => $status]);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Step', 'brief' => 'Brief', 'status' => TaskStatus::Running]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => vmEraGuest()->id, 'name' => 'task-'.$group->id, 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id,
        'branch' => 'task-'.$group->id, 'status' => InstanceState::SourceResolved, 'first_setup_pending' => false]);
    $group->taskable()->associate($instance)->save();

    return [$group, $host, $instance, Node::query()->where('name', 'gateway')->sole()];
}

/**
 * A group of the task VM era that still owns its workspace Instance on the host but has no task copy, as a stuck group does after the VMs
 * were removed.
 * It has subtask approvals of `$approved` in order, and a GitHub where `$origin['branch']`
 * lists the commits the group's task branch reaches, its head first; without that key the branch does not exist, and `unreadable` fails
 * the read. Any other key is a commit and the commits it reaches. The test may change `$origin` later. The group's pull request has the head
 * `$merged` and the state `$state`, when given. `$reads` records each read with the token it used.
 *
 * @param  list<string>  $approved
 * @param  array<string,list<string>|string>  $origin
 * @param  list<string>  $reads
 * @return array{0:Task,1:Instance,2:Node}
 */
function originWorld(array $approved, array &$origin, ?string $merged = null, TaskGroupStatus $status = TaskGroupStatus::Settling,
    GitHubPullRequestState $state = GitHubPullRequestState::Merged, array &$reads = []): array
{
    [, $project] = vmEraHost();
    vmEraControlNodes();
    app(TaskExtensionState::class)->enable();
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'read', 'read', 'hardimpactdev', 'organization', 'https://github.com/apps/read', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use (&$origin, &$reads, $merged, $state): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        // Each token names the permission it carries, so a read with the wrong token shows in `$reads`.
        $github->shouldReceive('repositoryPullRequestReadToken')->andReturn('pull-requests-read');
        $github->shouldReceive('repositoryReadToken')->andReturn('contents-read');
        $github->shouldReceive('branchHead')->andReturnUsing(function (string $token, GitHubRepository $repository, string $branch) use (&$origin, &$reads): ?string {
            $reads[] = "branch:{$token}";
            $listed = $origin['branch'] ?? null;

            return $listed === 'unreadable' ? throw GitHubApiException::unavailable() : (is_array($listed) ? $listed[0] : null);
        });
        $github->shouldReceive('compareCommits')->andReturnUsing(function (string $token, GitHubRepository $repository, string $base, string $head) use (&$origin, &$reads): GitHubCommitComparison {
            $reads[] = "compare:{$token}";
            $reached = collect($origin)->first(static fn ($listed): bool => is_array($listed) && ($listed[0] ?? null) === $head) ?? throw GitHubApiException::unavailable();

            return match (true) {
                $reached[0] === $base => new GitHubCommitComparison(GitHubComparisonStatus::Identical, $base, $base),
                in_array($base, $reached, true) => new GitHubCommitComparison(GitHubComparisonStatus::Ahead, $base, $base),
                default => new GitHubCommitComparison(GitHubComparisonStatus::Diverged, $base, str_repeat('0', 40)),
            };
        });
        $github->shouldReceive('pullRequest')->andReturnUsing(function (string $token) use (&$reads, $merged, $state): GitHubPullRequest {
            $reads[] = "pull:{$token}";

            return new GitHubPullRequest($state, null, null, $merged, 'main', mergedAt: $state === GitHubPullRequestState::Merged ? '2026-11-08T08:00:00Z' : null);
        });
    }));
    $url = $merged === null ? null : 'https://github.com/hardimpactdev/orbit/pull/77';
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => $status, 'pr_url' => $url, 'watched_pr_url' => $url]);
    $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Step', 'brief' => 'Brief', 'status' => TaskStatus::Completed]);
    foreach ($approved as $sha) {
        TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'type' => TaskCommentType::Approved, 'body' => 'Approved.',
            'author' => 'reviewer', 'commit_sha' => $sha, 'posted_at' => now()]);
    }
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => Node::query()->where('name', 'beast')->sole()->id, 'name' => 'task-'.$group->id,
        'source_layout' => InstanceSourceLayout::Checkout, 'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/task-'.$group->id,
        'task_workspace_owner_id' => $group->id, 'branch' => 'task-'.$group->id, 'status' => InstanceState::SourceResolved, 'first_setup_pending' => false]);

    return [$group, $workspace, Node::query()->where('name', 'gateway')->sole()];
}

/** One `task:complete` or `task:cancel` call from the Gateway Node. */
function groupCall(Node $gateway, Task $group, string $command): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->withHeader('Idempotency-Key', 'end-'.fake()->uuid())
        ->postJson("/api/v1/tasks/{$group->id}/{$command}");
}

// The grants release after the Pi loop (docs/reference/old-paths.md#the-pi-task-loop): no kind declares, and the grants file holds, a
// vault pattern that nothing writes. `tasks.provision` stays the request kind of `task:start`, which starts a task copy (an
// `instance.create`); it names that workflow, declares no pattern and issues no token.
const UNWRITTEN_VAULT_PATTERNS = ['^(swamp-proof-club-api-key|task-vm-slot-(wireguard|ssh-host|agent)-[1-9][0-9]*)$', '^task-vm-pi-(claim|token)-[1-9][0-9]*$',
    '^task-vm-pi-token-[1-9][0-9]*$', '^pr-comment-write-[a-f0-9-]{36}$', '^task-provision-deps-(composer|npm|token)-[1-9][0-9]*$'];

it('declares and grants no vault pattern that nothing writes, and keeps the nested fleet club key', function (): void {
    $declared = collect(app(DirectOperation::class)->definitions())->flatMap(static fn (array $definition): array => array_column($definition['execution_copies'] ?? [], 'key_pattern'));
    $granted = array_column(json_decode((string) file_get_contents(dirname(base_path(), 2).'/swamp/config/gateway-workflow-grants.json'), true, flags: JSON_THROW_ON_ERROR)['vaults'], 'key_pattern');

    expect($declared->intersect(UNWRITTEN_VAULT_PATTERNS)->values()->all())->toBe([])
        ->and(array_values(array_intersect($granted, UNWRITTEN_VAULT_PATTERNS)))->toBe([])
        ->and($declared->all())->toContain('^nested-fleet-club-api-key$')
        ->and($granted)->toContain('^nested-fleet-club-api-key$');
});

it('names in the registry only workflows and alternates that exist under swamp/workflows', function (): void {
    // The top-level name of each workflow file; Swamp starts a workflow by it.
    $names = collect(glob(dirname(base_path(), 2).'/swamp/workflows/*.yaml') ?: [])
        ->map(static fn (string $file): ?string => preg_match('/^name: "?([a-z0-9_.-]+)"?$/m', (string) file_get_contents($file), $match) === 1 ? $match[1] : null)->all();
    $named = collect(app(DirectOperation::class)->definitions())->flatMap(static fn (array $definition): array => [$definition['workflow'], ...($definition['alternate_workflows'] ?? [])])->unique();

    expect($names)->not->toBeEmpty()
        ->and($named->diff($names)->values()->all())->toBe([]);
});

it('keeps tasks.provision registered with the workflow its start runs, no vault pattern and no token issuer', function (): void {
    $definition = app(DirectOperation::class)->definition('tasks.provision');

    expect($definition['workflow'] ?? null)->toBe('operation-instance-create')
        ->and($definition)->not->toHaveKey('execution_copies')
        ->and($definition)->not->toHaveKey('github_token_issuer');
});

describe('a stuck group of the VM era', function (): void {
    // Group 4121 of Project 46: todo, no workspace, its old VM destroyed long ago.
    it('reads, lists and cancels a todo group whose old VM is destroyed, with no run and no job', function (): void {
        Queue::fake();
        [, $project] = vmEraHost();
        vmEraControlNodes();
        app(TaskExtensionState::class)->enable();
        $group = vmEraGroup($project);
        $gateway = Node::query()->where('name', 'gateway')->sole();
        $api = test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);

        $api->getJson("/api/v1/tasks/{$group->id}")->assertOk()->assertJsonPath('data.status', 'todo');
        expect(array_column($api->getJson('/api/v1/tasks?project='.$project->id)->assertOk()->json('data'), 'id'))->toContain($group->id);
        groupCall($gateway, $group, 'cancel')->assertSuccessful();

        $cancel = Operation::query()->where('kind', 'tasks.cancel')->sole();
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled)->and($cancel->status)->toBe(OperationStatus::Succeeded)
            ->and($cancel->input)->toBe(['group_id' => $group->id, 'project_id' => $project->id, 'instance_id' => null])
            ->and(Operation::query()->count())->toBe(1);
        Queue::assertNothingPushed();
    });

    // A group that owns a workspace but no task copy: its cancel ends it with no run.
    it('cancels a group that owns a workspace but no task copy without a run', function (): void {
        Queue::fake();
        $origin = [];
        [$group, , $gateway] = originWorld([], $origin);

        groupCall($gateway, $group, 'cancel')->assertSuccessful();

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled)
            ->and(Operation::query()->where('kind', 'tasks.cancel')->sole()->status)->toBe(OperationStatus::Succeeded)
            ->and(Operation::query()->where('kind', '!=', 'tasks.cancel')->exists())->toBeFalse();
        Queue::assertNothingPushed();
    });

    // A group that still holds a VM workspace has no task copy: each call that needs a workspace refuses by name and starts nothing.
    it('refuses a sync and a check of a group without a task copy by name, and starts nothing', function (string $call, bool $subtask, array $body): void {
        [$group, , , $gateway] = vmEraWorkspaceGroup();
        $target = $subtask ? $group->tasks()->sole()->id : $group->id;

        test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->withHeader('Idempotency-Key', 'call-'.fake()->uuid())
            ->postJson("/api/v1/tasks/{$target}/{$call}", $body)
            ->assertStatus(409)->assertJsonPath('error.code', 'tasks.no_workspace')->assertJsonPath('error.message', 'The group requires its task copy.');

        expect(Operation::query()->exists())->toBeFalse();
    })->with([
        'sync' => ['sync', false, []],
        'check' => ['check', true, ['phase' => 'baseline']],
    ]);

    it('refuses a publish of a group without a task copy by name, and starts nothing', function (): void {
        [$group, , , $gateway] = vmEraWorkspaceGroup();

        test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->withHeader('Idempotency-Key', 'call-'.fake()->uuid())
            ->postJson("/api/v1/tasks/{$group->id}/publish", ['check' => 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'tasks.no_workspace')->assertJsonPath('error.message', 'The group requires its task copy.');

        expect(Operation::query()->exists())->toBeFalse();
    });

    it('completes a settling group that holds a VM workspace, starts no release and asks for removal assistance', function (): void {
        Queue::fake();
        [$group, , $instance, $gateway] = vmEraWorkspaceGroup(TaskGroupStatus::Settling);
        $group->tasks()->update(['status' => TaskStatus::Completed]);

        groupCall($gateway, $group, 'complete')->assertOk();

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)->and($group->assistance_kind)->toBe(AssistanceKind::RemovalFailure)
            ->and($group->assistance_reason)->toBe('Workspace removal failed: This workspace is no task copy. Task VMs and their release are removed, so Orbit cannot remove it.')
            ->and($group->taskable_id)->toBe($instance->id)
            ->and(Operation::query()->exists())->toBeFalse();
        Queue::assertNothingPushed();
    });

    it('refuses the cancel of a group that holds a VM workspace by name before it stops running work, and records nothing', function (): void {
        Queue::fake();
        [$group, $host, , $gateway] = vmEraWorkspaceGroup();
        $turn = Operation::query()->create(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $host->id,
            'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);

        groupCall($gateway, $group, 'cancel')->assertStatus(409)->assertJsonPath('error.code', 'tasks.workspace_ownership_refused');

        // The refusal comes before the cancel stops running work.
        expect(Operation::query()->whereKeyNot($turn->id)->exists())->toBeFalse()->and($turn->refresh()->cancel_requested_at)->toBeNull()
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Running);
        Queue::assertNothingPushed();
    });
});

it('refuses task:complete while origin lacks the latest approval, and completes once origin has it, with no release', function (): void {
    [$a, $b] = [str_repeat('a', 40), str_repeat('b', 40)];
    // A merged and GitHub deleted the branch; B was approved later.
    $origin = [$a => [$a]];
    [$group, , $gateway] = originWorld([$a, $b], $origin, merged: $a, status: TaskGroupStatus::Running);

    groupCall($gateway, $group, 'complete')->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished')
        ->assertJsonPath('error.details.commit', $b)->assertJsonPath('error.details.branch', 'task-'.$group->id);
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->watched_pr_completion)->toBeNull();

    // task:publish pushed B; now the group completes.
    $origin['branch'] = [$b, $a];
    groupCall($gateway, $group, 'complete')->assertOk();

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)->and(Operation::query()->exists())->toBeFalse();
});

// Group 4474 of Project 46: waiting for review, its pull request merged.
it('takes the merged pull request\'s head for a deleted task branch, so a merged latest approval completes', function (): void {
    $a = str_repeat('a', 40);
    $origin = [$a => [$a]];
    [$group, , $gateway] = originWorld([$a], $origin, merged: $a, status: TaskGroupStatus::WaitingForReview);

    groupCall($gateway, $group, 'complete')->assertOk();

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed);
});

it('refuses task:cancel while origin lacks the latest approval, admitting nothing, and ends the group once origin has it', function (): void {
    [$a, $b] = [str_repeat('a', 40), str_repeat('b', 40)];
    $origin = ['branch' => [$a]];
    [$group, , $gateway] = originWorld([$a, $b], $origin);

    groupCall($gateway, $group, 'cancel')->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished')
        ->assertJsonPath('error.details.commit', $b);
    expect(Operation::query()->exists())->toBeFalse();

    $origin['branch'] = [$b, $a];
    groupCall($gateway, $group, 'cancel')->assertSuccessful();

    expect(Operation::query()->where('kind', 'tasks.cancel')->sole()->input)->not->toHaveKey('release')->not->toHaveKey('push')
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
});

it('reads the task branch and compares with a contents:read token, and the pull request with a pull_requests:read token', function (): void {
    $a = str_repeat('a', 40);
    $origin = ['branch' => [$a]];
    $reads = [];
    [$group, , $gateway] = originWorld([$a], $origin, reads: $reads);
    groupCall($gateway, $group, 'complete')->assertOk();
    expect($reads)->toBe(['branch:contents-read', 'compare:contents-read']);
});

it('reads a merged pull request with its own token when its branch is gone, and compares its head with the contents token', function (): void {
    $a = str_repeat('a', 40);
    $origin = [$a => [$a]];
    $reads = [];
    [$merged, , $gateway] = originWorld([$a], $origin, merged: $a, status: TaskGroupStatus::Running, reads: $reads);
    groupCall($gateway, $merged, 'complete')->assertOk();
    expect($reads)->toContain('branch:contents-read', 'pull:pull-requests-read', 'compare:contents-read')
        ->and($reads)->not->toContain('compare:pull-requests-read', 'branch:pull-requests-read');
});

// Group 6078 of Project 78: its pull request merged, but the group kept the watched state "open" after task:complete.
it('records how the watched pull request ended as its state when task:complete ends a running external group', function (GitHubPullRequestState $state): void {
    $a = str_repeat('a', 40);
    $origin = ['branch' => [$a]];
    [$group, , $gateway] = originWorld([$a], $origin, merged: $a, status: TaskGroupStatus::Running, state: $state);
    $group->update(['orchestration' => TaskOrchestration::External, 'watched_pr_state' => 'open']);

    groupCall($gateway, $group, 'complete')->assertOk();

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)
        ->and($group->watched_pr_state)->toBe($state->value)->and($group->watched_pr_completion)->toBe($state->value);
})->with([GitHubPullRequestState::Merged, GitHubPullRequestState::Closed]);

it('refuses when the task branch cannot be read, though the merged pull request\'s head is the approved commit', function (): void {
    $a = str_repeat('a', 40);
    $origin = ['branch' => 'unreadable', $a => [$a]];
    $reads = [];
    [$group, , $gateway] = originWorld([$a], $origin, merged: $a, status: TaskGroupStatus::Running, reads: $reads);

    groupCall($gateway, $group, 'complete')->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished');
    // The completion reads its watched pull request first; the failed branch read ends the check before any compare.
    expect($reads)->toBe(['pull:pull-requests-read', 'branch:contents-read'])->and($group->refresh()->status)->toBe(TaskGroupStatus::Running);
});

it('refuses when the task branch is gone and its pull request closed without a merge, though the head is the approved commit', function (): void {
    $a = str_repeat('a', 40);
    $origin = [$a => [$a]];
    [$group, , $gateway] = originWorld([$a], $origin, merged: $a, state: GitHubPullRequestState::Closed);

    groupCall($gateway, $group, 'complete')->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Settling);
});

it('leaves the group\'s open operations untouched when a complete or a cancel refuses', function (string $command): void {
    [$a, $b] = [str_repeat('a', 40), str_repeat('b', 40)];
    $origin = ['branch' => [$a]];
    [$group, $workspace, $gateway] = originWorld([$a, $b], $origin);
    $turn = Operation::query()->create(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $workspace->node_id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);

    groupCall($gateway, $group, $command)->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished');

    expect($turn->refresh()->status)->toBe(OperationStatus::Running)->and($turn->cancel_requested_at)->toBeNull()
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Settling);
})->with(['complete', 'cancel']);

it('schedules no task VM image refresh, and offers no artisan command that starts one', function (): void {
    Artisan::call('schedule:list');

    expect(Artisan::output())->not->toContain('RefreshTaskVmImages')->not->toMatch('/^\s*20 3 \* \* \*/m')
        ->and(array_keys(Artisan::all()))->not->toContain('task-vms:refresh-images')->not->toContain('task-vms:refresh-image');
});

it('schedules no task VM host sweep, idle release or parked VM observation', function (): void {
    Artisan::call('schedule:list');

    expect(Artisan::output())->not->toContain('SweepTaskVmHosts')->not->toContain('ReleaseIdleTaskVms')->not->toContain('ObserveParkedTaskVms');
});

/*
 * The one placement rule (docs/reference/projects.md#nested-fleet-setting): task work that needs a task VM refuses a nested-fleet
 * Project before it records a place, an identity, a workspace, a wait or an Operation. Its task start takes the copy path instead.
 */
describe('nested-fleet Projects', function (): void {
    /** @return array<string, int> */
    $rows = static fn (): array => ['instances' => Instance::query()->count(), 'nodes' => Node::query()->count(),
        'operations' => Operation::query()->count(), 'tasks' => Task::query()->count(), 'waiting' => Task::query()->whereNotNull('capacity_wait_reason')->count()];
    $refused = static function (Closure $admit): void {
        expect($admit)->toThrow(function (ResourceOperationException $error): void {
            expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(503)
                ->and($error->getMessage())->toBe('Only instance:create makes Instances of a nested-fleet Project, as containers.');
        });
    };
    $flag = static fn (Project $project) => DB::table('projects')->where('id', $project->id)->update(['nested_fleet' => true]);

    // A task start of such a Project takes the copy path (TaskStart): without a default to copy it is refused by name, and no VM place is used.
    it('starts no task VM for a Project flagged through the API, with or without a free place', function (int $places) use ($rows): void {
        Queue::fake();
        [, $project] = vmEraHost(taskPlaces: $places);
        vmEraControlNodes();
        vmEraServices();
        $this->withoutMiddleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class]);
        config(['instances.nested_fleet_projects' => [$project->id]]);
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])->assertOk();
        $group = vmEraGroup($project->refresh());
        $before = $rows();
        expect(fn () => app(TaskProvisionIntent::class)->start($group, 'test', null))
            ->toThrow(fn (ResourceOperationException $error) => expect([$error->errorCode, $error->status])->toBe(['tasks.copy_source_unavailable', 409]));
        expect($rows())->toBe($before)->and($group->refresh()->status)->toBe(TaskGroupStatus::Todo)->and($group->taskable_id)->toBeNull();
    })->with(['free place' => [2], 'full pool' => [0]]);

    // A flagged proof Project proves in copies (docs/cli/proof/run.mdx#the-run): the proof is recorded and takes the copy path, never a VM
    // place. With no copy place to spare (the default cap of one copy) it waits, with no group, VM or child.
    it('records proof:run for a flagged proof Project and admits it to copies, never to a task VM place', function (bool $ci) use ($rows, $flag): void {
        $caller = vmEraCiProofWorld(2);
        $flag(Project::query()->findOrFail(config('proof.project')));
        $intent = app(ProofRunIntent::class);
        $proof = $intent->start(str_repeat('a', 40), false, 'test', null, authenticatedCaller: $ci ? $caller : null);
        $before = $rows();
        $intent->admit($proof->id);
        expect($proof->refresh()->status)->toBe(OperationStatus::Waiting)->and($proof->current_step)->toBe(ProofCopies::WaitStep)
            ->and([...$rows(), 'waiting' => 0])->toBe([...$before, 'waiting' => 0])->and(ProofRunSettlement::children($proof->id))->toHaveCount(0);
    })->with(['operator' => [false], 'CI' => [true]]);
});
