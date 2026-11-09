<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\TaskVms\StartTaskVmResumeAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Data\Tasks\StartTaskPrepFixData;
use App\Data\Tasks\TaskTurnData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCommitComparison;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofChangedFile;
use App\Domain\Proof\ProofChangedFiles;
use App\Domain\Proof\ProofCommandProjection;
use App\Domain\Proof\ProofCommitRange;
use App\Domain\Proof\ProofCommitSource;
use App\Domain\Proof\ProofRunIntent;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Proof\ProofSelectionList;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskBuildIntent;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskCancelProjection;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupEnding;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskIdleSave;
use App\Domain\Tasks\TaskIdleSaveProjection;
use App\Domain\Tasks\TaskPrepFixIntent;
use App\Domain\Tasks\TaskProvisionIntent;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Domain\Tasks\TaskReviewMode;
use App\Domain\Tasks\TaskReviewRoundIntent;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnIntent;
use App\Domain\Tasks\TaskTurnProjection;
use App\Domain\Tasks\TaskVmAdmission;
use App\Domain\Tasks\TaskVmIdleRelease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmContainers;
use App\Domain\TaskVms\TaskVmConvergeInput;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Domain\TaskVms\TaskVmNestedFleet;
use App\Domain\TaskVms\TaskVmPromotedImage;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmRuntime;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmSlotIdentity;
use App\Domain\TaskVms\TaskVmSlotPool;
use App\Domain\TaskVms\TaskVmSlotReleaseProjection;
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Infrastructure\Swamp\SwampWorkerKnownHostsInput;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Jobs\Tasks\AdmitTaskVm;
use App\Jobs\TaskVms\ReleaseIdleTaskVms;
use App\Jobs\TaskVms\ReleaseTaskVmSlot;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Orbit\Extensions\AiRouter\Domain\AiRouterSecrets;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

uses(LazilyRefreshDatabase::class);

/** A task VM host of `$taskPlaces` slots, with a base snapshot unless `$snapshot` is false. */
function slotPoolHost(int $taskPlaces = 2, bool $snapshot = true, int $ciPlaces = 2): array
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $host = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    config(['proof.project' => $project->id]);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', $taskPlaces, 4, '8GiB', '20GiB', ciPlaces: $ciPlaces)],
        '/pi', str_repeat('a', 64), ['gpt-6']));
    $base = $snapshot ? ['project' => 'orbit-tasks', 'type' => 'snapshot', 'vm' => 'beast-base', 'seed' => 'beast-base-seed', 'snapshot' => 'r20261107120000']
        : ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => str_repeat('b', 64)];
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now(), 'images' => ['base' => $base]]);

    return [$host, $project, $build];
}

/** A todo task group of the Project. */
function slotPoolGroup(Project $project): Task
{
    return Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo]);
}

/** The Gateway, Swamp and VPN Node a release intent names, and a Swamp dispatch that starts nothing unless `$dispatch` is false. */
function slotPoolSweepWorld(bool $dispatch = true): void
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
function slotPoolSocket(): object
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

it('takes the lowest free slot number and mints a fresh identity, its Node active with the public keys pinned', function (): void {
    [$host, $project] = slotPoolHost();
    $pool = app(TaskVmSlotPool::class);

    $first = $pool->take(slotPoolGroup($project));
    $second = $pool->take(slotPoolGroup($project));

    expect($first->name)->toBe('beast-tvm-1')->and($first->slot_number)->toBe(1)->and($first->state)->toBe(TaskVmState::Provisioning)
        ->and($second->name)->toBe('beast-tvm-2')->and($second->wireguard_ip)->not->toBe($first->wireguard_ip);
    $node = $first->node;
    expect($node)->toBeInstanceOf(Node::class)->and($node->status)->toBe(LifecycleStatus::Active)
        ->and($node->agent_secret_hash)->toBe(hash('sha256', (string) $first->agent_secret))
        ->and($node->wireguard_public_key)->toBe(TaskVmSlotIdentity::wireguardPublicKey((string) $first->wireguard_private_key))
        ->and($node->ssh_host_fingerprint)->toBe(TaskVmSlotIdentity::sshFingerprint((string) $first->ssh_host_private_key))
        ->and($node->roles()->where('role', RoleName::AppDev)->sole()->status)->toBe(LifecycleStatus::Active)
        // The run of a task VM carries its pin; the pin admits no publication of the workers' known hosts.
        ->and(Operation::query()->where('kind', 'node.worker_known_hosts')->exists())->toBeFalse();

    // A released slot's number is free again; the new identity shares nothing with the old one.
    $old = [$first->wireguard_private_key, $first->agent_secret];
    app(TaskVmSlotReleaseProjection::class)->record($first, true, null);
    $third = $pool->take(slotPoolGroup($project));
    expect($third->slot_number)->toBe(1)->and($third->id)->not->toBe($first->id)
        ->and([$third->wireguard_private_key, $third->agent_secret])->not->toBe($old);
});

it('admits each pool independently and holds failed VMs until confirmed deletion', function (): void {
    [, $project] = slotPoolHost(taskPlaces: 1, ciPlaces: 1);
    $pool = app(TaskVmSlotPool::class);
    $task = $pool->take(slotPoolGroup($project));
    $ci = $pool->take(slotPoolGroup($project), proof: true);
    expect($task->capacity_pool)->toBe('task')->and($ci->capacity_pool)->toBe('ci');
    expect(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class);
    $task->update(['state' => TaskVmState::Failed]);
    expect(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class);
    app(TaskVmSlotReleaseProjection::class)->record($task, true, null);
    expect($pool->take(slotPoolGroup($project))->capacity_pool)->toBe('task');
    expect(fn () => $pool->take(null))->toThrow(TaskCapacityException::class);
});

it('starts a group only from a base snapshot, while a refresh takes a slot on its own host before the host has one', function (): void {
    [$host, $project] = slotPoolHost(snapshot: false);
    $pool = app(TaskVmSlotPool::class);

    expect(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class)
        ->and($pool->take(null, $host->id)->group_id)->toBeNull();
});

it('deletes the Node, its workspace Instance and the keys of a released slot', function (): void {
    [, $project] = slotPoolHost();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $node = $slot->node;
    $slot->update(['state' => TaskVmState::Ready]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);

    app(TaskVmSlotReleaseProjection::class)->record($slot, true, null);

    expect($slot->refresh()->state)->toBe(TaskVmState::Destroyed)->and($slot->group_id)->toBeNull()->and($slot->node_id)->toBeNull()
        ->and($slot->wireguard_private_key)->toBeNull()->and($slot->agent_secret)->toBeNull()
        ->and(Node::query()->whereKey($node->id)->exists())->toBeFalse()->and(Instance::query()->whereKey($instance->id)->exists())->toBeFalse();
});

it('mints keys whose public halves match what the real tools derive', function (): void {
    [$host, $project] = slotPoolHost();
    $slot = TaskVm::query()->create(['host_node_id' => $host->id, 'slot_project_id' => $project->id, 'slot_number' => 1, 'provider' => TaskVmProviderKind::Incus,
        'size' => TaskVmSize::Large, 'name' => 'beast-tvm-1', 'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.201']);
    $keys = app(TaskVmSlotIdentity::class)->ensure($slot);

    expect(TaskVmSlotIdentity::sshPublicKey((string) $keys->ssh_host_private_key))->toStartWith('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5')
        ->and(TaskVmSlotIdentity::sshFingerprint((string) $keys->ssh_host_private_key))->toStartWith('SHA256:')
        ->and(strlen((string) base64_decode(TaskVmSlotIdentity::wireguardPublicKey((string) $keys->wireguard_private_key))))->toBe(32);
});

it('submits a release without secrets with an empty secret map, which the workflow takes as an object', function (): void {
    [$host, $project] = slotPoolHost();
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $operation = Operation::query()->create(['kind' => TaskVmSlotPool::ReleaseKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Queued, 'input' => ['intent' => ['host' => [], 'slot' => []], 'execution_secrets' => []], 'triggered_by' => 'test']);

    expect(json_encode(app(DirectOperation::class)->workflowInputs($operation)['execution_secrets']))->toBe('{}');
});

it('fails a slot whose release start was refused, keeping its Node for the sweep', function (): void {
    [$host, $project] = slotPoolHost();
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $slot->update(['state' => TaskVmState::Releasing, 'group_id' => null]);
    $operation = Operation::query()->create(['kind' => TaskVmSlotPool::ReleaseKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Failed, 'error_code' => 'swamp.start_refused', 'input' => [], 'triggered_by' => 'test']);

    app(TaskVmSlotReleaseProjection::class)->refused($operation);

    expect($slot->refresh()->state)->toBe(TaskVmState::Failed)->and($slot->error_code)->toBe('swamp.start_refused')
        ->and($slot->node)->toBeInstanceOf(Node::class);
});

it('has the sweep release each slot that no group holds and no Operation works on, and leaves the others', function (): void {
    [$host, $project] = slotPoolHost(taskPlaces: 12);
    $pool = app(TaskVmSlotPool::class);
    $stale = $pool->take(slotPoolGroup($project));
    $held = $pool->take(slotPoolGroup($project));
    $busy = $pool->take(slotPoolGroup($project));
    $stale->update(['group_id' => null, 'state' => TaskVmState::Failed]);
    $busy->update(['group_id' => null, 'state' => TaskVmState::Failed]);
    Operation::query()->create(['kind' => TaskVmSlotPool::ReleaseKind, 'target_type' => 'task_vm', 'target_id' => $busy->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);
    slotPoolSweepWorld();

    app()->call([new SweepTaskVmHosts, 'handle']);

    $releases = Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('triggered_by', 'task-vm-sweep')->get();
    expect($releases->pluck('target_id')->all())->toBe([$stale->id])
        ->and($releases->sole()->input['intent']['hub_peer']['public_key'])->toBe(TaskVmSlotIdentity::wireguardPublicKey((string) $stale->wireguard_private_key))
        ->and($stale->refresh()->state)->toBe(TaskVmState::Releasing)
        ->and($held->refresh()->state)->toBe(TaskVmState::Provisioning)->and($busy->refresh()->state)->toBe(TaskVmState::Failed);
});

it('refuses a group of a Project whose checkout the base does not carry', function (): void {
    slotPoolHost();
    $other = Project::query()->create(['name' => 'bench', 'slug' => 'bench', 'repository_url' => 'https://github.com/laravel/laravel.git', 'default_branch' => '13.x', 'root' => 'public']);

    expect(fn () => app(TaskVmSlotPool::class)->take(slotPoolGroup($other)))->toThrow(TaskVmException::class, 'its base carries only that checkout')
        ->and(TaskVm::query()->count())->toBe(0);
});

it('refuses work of another Project at once, so it never waits in or holds up the task queue', function (): void {
    Queue::fake();
    [, $project] = slotPoolHost(taskPlaces: 0);
    slotPoolSweepWorld();
    slotPoolClaimServices();
    $earlier = app(TaskProvisionIntent::class)->start(slotPoolGroup($project), 'test', null, str_repeat('a', 40));
    $other = Project::query()->create(['name' => 'website', 'slug' => 'website', 'repository_url' => 'https://github.com/laravel/laravel.git', 'default_branch' => '13.x', 'root' => 'public']);
    $foreign = slotPoolGroup($other);

    expect($earlier->status)->toBe(OperationStatus::Waiting);
    try {
        app(TaskProvisionIntent::class)->start($foreign, 'test', null, str_repeat('a', 40));
        $this->fail('A group of another Project must be refused.');
    } catch (TaskVmException $exception) {
        expect($exception->errorCode)->toBe('task_vm.project_unsupported')->and($exception->status)->toBe(409);
    }
    expect(Operation::query()->where('target_id', $foreign->id)->exists())->toBeFalse()
        ->and($foreign->refresh()->status)->toBe(TaskGroupStatus::Todo)->and($foreign->capacity_wait_reason)->toBeNull();

    // A wait recorded before admission refused such a group fails with the refusal, even behind an earlier waiter.
    $waiting = app(TaskVmAdmission::class)->wait('tasks.provision', 'task_group', $foreign->id, (int) $earlier->node_id, ['commit' => str_repeat('a', 40)], 'test', null);
    app(TaskVmAdmission::class)->admit($waiting->id);
    expect($waiting->refresh()->status)->toBe(OperationStatus::Failed)->and($waiting->error_code)->toBe('task_vm.project_unsupported')
        ->and($foreign->refresh()->capacity_wait_reason)->toBeNull()->and($earlier->refresh()->status)->toBe(OperationStatus::Waiting);
});

it('has the sweep leave a host alone while its refresh is open, and go on past a row whose release cannot be built', function (): void {
    [$host, $project] = slotPoolHost(taskPlaces: 12);
    $pool = app(TaskVmSlotPool::class);
    $broken = $pool->take(slotPoolGroup($project));
    $stale = $pool->take(slotPoolGroup($project));
    // A row without its Node has no hub peer to remove, so its intent cannot be built.
    $broken->update(['group_id' => null, 'state' => TaskVmState::Failed, 'node_id' => null]);
    $stale->update(['group_id' => null, 'state' => TaskVmState::Failed]);
    slotPoolSweepWorld();
    $refresh = Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);

    app()->call([new SweepTaskVmHosts, 'handle']);
    expect(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse();

    $refresh->update(['status' => OperationStatus::Succeeded]);
    app()->call([new SweepTaskVmHosts, 'handle']);
    expect(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->pluck('target_id')->all())->toBe([$stale->id])
        ->and($broken->refresh()->state)->toBe(TaskVmState::Failed);
});

it('leaves task VM Nodes out of the workers\' known hosts and keeps every other pinned Node', function (): void {
    [$host, $project] = slotPoolHost();
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    slotPoolSweepWorld();

    $content = app(SwampWorkerKnownHostsInput::class)->capture()['known_hosts_content'];

    expect(SwampWorkerKnownHostsInput::covers($slot->node))->toBeFalse()->and(SwampWorkerKnownHostsInput::covers($host))->toBeTrue()
        ->and($content)->toContain('10.44.0.7 ssh-ed25519 fixture-public-key')->not->toContain((string) $slot->wireguard_ip.' ');
});

it('unbinds a failed start\'s group from the workspace its release deletes, and its cancel waits for that release, then closes it', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld();
    app(TaskExtensionState::class)->enable();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Failed])->save();
    $release = Operation::query()->create(['kind' => TaskVmSlotPool::ReleaseKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'task-vm']);

    expect(fn () => app(TaskCancelIntent::class)->start($group, 'test', null))->toThrow(ResourceOperationException::class, 'still running');

    app(TaskVmSlotReleaseProjection::class)->record($slot, true, null);
    $release->update(['status' => OperationStatus::Succeeded]);
    expect($group->refresh()->taskable_id)->toBeNull();

    $cancel = app(TaskCancelIntent::class)->start($group, 'test', null);
    expect($cancel->input['instance_id'])->toBeNull()->and($cancel->input['release'])->toBeNull()
        ->and(app(TaskCancelIntent::class)->input($cancel)['intent']['group_id'])->toBe($group->id);
});

it('lets a cancel admitted before a failed start\'s release still close the group the release unbound, and keeps the slot destroyed', function (): void {
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    app(TaskExtensionState::class)->enable();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Failed])->save();
    // The cancel is admitted while the release job has not started its Operation yet.
    $cancel = app(TaskCancelIntent::class)->start($group, 'test', null);
    app(TaskVmSlotReleaseProjection::class)->record($slot->refresh(), true, null);

    expect(app(TaskCancelIntent::class)->input($cancel)['intent']['group_id'])->toBe($group->id);
    app(TaskGroupEnding::class)->failed($cancel->input, 'task_vm.slot_release_failed');
    expect($slot->refresh()->state)->toBe(TaskVmState::Destroyed);
});

it('has a task start converge reach its VM over WireGuard with the pinned key of its admission, and the base VM through its host', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld();
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $commit = str_repeat('a', 40);
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));

    $start = app(TaskVmConvergeInput::class)->for($host, $commit, 'task-1', $slot->node)['converge'];
    $base = app(TaskVmConvergeInput::class)->for($host, $commit)['converge'];

    expect($start['direct'])->toBeTrue()
        ->and($start['ssh']['target'])->toBe(['host' => $slot->wireguard_ip, 'user' => 'orbit', 'port' => 22,
            'host_keys' => [$slot->node->ssh_host_key_type.' '.$slot->node->ssh_host_key]])
        ->and($base)->not->toHaveKey('direct')->and($base['ssh']['target']['host'])->toBe('')
        // What every task VM shares goes into the base, not into each start's identity.
        ->and($base['boot']['trust'])->toBe(['certificate' => "-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n",
            'control_ip' => '10.44.0.1', 'gateway_ip' => '10.44.0.1', 'pi_port' => app(PiConnection::class)->port()]);
});

it('gives every converge what a base VM without a nested fleet builds it from', function (): void {
    [$host] = slotPoolHost();
    slotPoolSweepWorld();
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));

    $converge = app(TaskVmConvergeInput::class)->for($host, str_repeat('a', 40))['converge'];
    $fleet = $converge['fleet'];

    expect($converge['nested'])->toBe(['subnet' => '10.232.2.0/24', 'interface' => 'oe-nested'])
        ->and(array_keys($fleet))->toBe(['guests', 'network', 'operator', 'layout', 'proof_network', 'checkout', 'bootstrap'])
        ->and($fleet['guests'])->toBe(['gateway' => 'nested-gateway', 'app-dev' => 'nested-app-dev', 'app-prod' => 'nested-app-prod'])
        ->and($fleet['network'])->toBe($converge['nested']['interface'])
        ->and($fleet['operator'])->toBe(['address' => '10.45.0.10', 'hub' => '10.45.0.1', 'network' => '10.45.0.0/24'])
        ->and($fleet['layout'])->toBe(TaskVmNestedFleet::Layout)
        ->and($fleet['proof_network'])->toBe($converge['nested']['subnet'])
        ->and($fleet['checkout']['path'])->toBe('/home/orbit/orbit')
        ->and($fleet['checkout']['binds']['app-dev'])->toBe(['home-orbit-orbit-apps-gateway-.env.mount', 'home-orbit-orbit-apps-gateway-storage.mount', 'home-orbit-orbit-apps-gateway-bootstrap-cache.mount'])
        ->and(collect($fleet['checkout']['units'])->groupBy('guest')->map->count()->all())->toBe(['nested-gateway' => 6, 'nested-app-dev' => 3]);
    // The run adds the Swamp pin of the recipe, the machine with its host key and the release; the Gateway sends the rest.
    expect(array_keys($fleet['bootstrap']))->toBe(['apt_proxy', 'gateway', 'operator', 'agent_delivery'])
        ->and($fleet['bootstrap']['apt_proxy'])->toBe('http://10.232.2.1:3142')
        ->and($fleet['bootstrap']['gateway'])->toBe(['url' => 'https://10.45.0.1', 'wireguard_ip' => '10.45.0.1', 'wireguard_subnet' => '10.45.0.0/24'])
        ->and($fleet['bootstrap']['operator'])->toBe(['checkout' => $converge['checkout']['path'], 'wireguard_ip' => '10.45.0.10'])
        ->and(json_decode($fleet['bootstrap']['agent_delivery'], true, flags: JSON_THROW_ON_ERROR))->toHaveKeys(['commit', 'version', 'artifacts'])
        ->and(json_encode($fleet))->not->toContain('club');
});

it('writes the guard origin and the one fixed model key into every claim, and a start or release carries no model intent or secret', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld(dispatch: false);
    $services = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.3', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:services', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'services-public-key']));
    app(AiRouterState::class)->enable($services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    Operation::query()->forceCreate(['kind' => 'ai_router.task_guard', 'target_type' => 'node', 'target_id' => $services->id, 'node_id' => $services->id,
        'status' => OperationStatus::Succeeded, 'input' => ['intent' => ['state' => 'present']]]);
    $socket = slotPoolSocket();
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));
    $runs = app(TaskVmSlotRuns::class);
    $pool = app(TaskVmSlotPool::class);
    $first = $pool->take(slotPoolGroup($project));
    $second = $pool->take(slotPoolGroup($project));

    // The identity of a start reads the hub's public key from the Gateway home.
    $home = sys_get_temp_dir().'/orbit-claim-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($home.'/wireguard');
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('h', 32))."\n");
    config(['orbit.home' => $home]);
    try {
        $start = $runs->claim($first, 'task-1', str_repeat('a', 40));
        $other = $runs->claim($second, 'task-2', str_repeat('a', 40));
    } finally {
        File::deleteDirectory($home);
    }

    $file = static function (array $claim) use ($socket): array {
        [$token, $models] = explode("\n", $socket->put($claim['execution_secrets']['claim']['key']), 2);

        return [$token, json_decode($models, true, flags: JSON_THROW_ON_ERROR)['providers']['orbit-task-vm']];
    };
    [$token, $provider] = $file($start);
    [$otherToken, $otherProvider] = $file($other);
    $first->refresh();
    $second->refresh();
    $key = app(AiRouterSecrets::class)->task();

    // The Pi models file holds the guard origin (port 8318, not the router's own 8317) and the fixed key, the same for every VM.
    expect($provider['baseUrl'])->toBe('http://10.44.0.3:8318/v1')->and($provider['apiKey'])->toBe($key)
        ->and($otherProvider['apiKey'])->toBe($key)->and($otherProvider['baseUrl'])->toBe($provider['baseUrl'])
        // The Pi token stays one per claim.
        ->and($token)->toBe($first->pi_token)->and($otherToken)->toBe($second->pi_token)->and($token)->not->toBe($otherToken)
        // No model intent, no model secret and no model vault key: the start never calls the model router.
        ->and($start['intent'])->not->toHaveKey('model')
        ->and(array_keys($start['execution_secrets']))->toBe(['wireguard', 'ssh_host', 'agent_secret', 'claim'])
        ->and(json_encode($start))->not->toContain($key)
        ->and(array_filter(array_column(array_column($socket->frames, 'payload'), 'key'), static fn (string $name): bool => str_contains($name, 'model')))->toBe([]);

    // A release has nothing to revoke.
    $release = $runs->release($first);
    expect($release['intent'])->not->toHaveKey('model')->and($release['execution_secrets'])->toBe([]);
});

it('refuses a task start while the ai-router has no guard', function (): void {
    [, $project] = slotPoolHost();
    slotPoolSweepWorld(dispatch: false);
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));

    expect(fn () => app(TaskVmSlotRuns::class)->claim($slot, 'task-1', str_repeat('a', 40)))->toThrow(ResourceOperationException::class, 'ai-router');
});

it('rebuilds the base from stock only when asked: the run deletes the base first, keeps no recorded snapshot, and cleans the host after', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld(dispatch: false);
    $services = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.3', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:services', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'services-public-key']));
    app(AiRouterState::class)->enable($services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    Operation::query()->forceCreate(['kind' => 'ai_router.task_guard', 'target_type' => 'node', 'target_id' => $services->id, 'node_id' => $services->id,
        'status' => OperationStatus::Succeeded, 'input' => ['intent' => ['state' => 'present']]]);
    slotPoolSocket();
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));
    $commit = str_repeat('d', 40);
    $home = sys_get_temp_dir().'/orbit-refresh-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($home.'/wireguard');
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('h', 32))."\n");
    File::ensureDirectoryExists($home.'/releases/'.substr($commit, 0, 12).'/apps/gateway');
    file_put_contents($home.'/releases/'.substr($commit, 0, 12).'/REVISION', $commit."\n");
    // The converge reads the machine recipe of the release.
    symlink(base_path('resources'), $home.'/releases/'.substr($commit, 0, 12).'/apps/gateway/resources');
    symlink('releases/'.substr($commit, 0, 12), $home.'/orbit');
    config(['orbit.home' => $home, 'orbit.gateway_checkout' => $home.'/orbit/apps/gateway', 'swamp.proof_club_api_key' => 'club']);
    try {
        $refresh = app(TaskVmImageRefreshIntent::class)->input($host)['intent'];
        $rebuild = app(TaskVmImageRefreshIntent::class)->input($host, fromStock: true)['intent'];
    } finally {
        File::deleteDirectory($home);
    }

    expect($refresh['host'])->toMatchArray(['rebuild' => false, 'current' => 'r20261107120000', 'image' => 'ubuntu-26.04-vm'])
        ->and($rebuild['host'])->toMatchArray(['rebuild' => true, 'current' => null])->and($rebuild['with_pi'])->toBeTrue()
        ->and($refresh['host']['clean'])->toBe(['keep_images' => ['ubuntu-26.04-vm'], 'prefixes' => ['orbit-topology-seed-', 'orbit-baseline-'],
            'retired_projects' => ['orbit-baselines', 'orbit-measure-nr'], 'image_projects' => ['orbit-proto']]);
});

it('withdraws the recorded base for a rebuild, and refuses a rebuild while a refresh is open', function (): void {
    [$host, $project] = slotPoolHost();
    $images = app(TaskVmPromotedImage::class);

    expect($images->latest($host, TaskVmRuntime::Vm))->not->toBeNull();
    $images->withdraw($host, TaskVmRuntime::Vm);
    // No start copies a withdrawn base; the pool skips a host without one.
    expect($images->latest($host, TaskVmRuntime::Vm))->toBeNull()
        ->and(fn () => app(TaskVmSlotPool::class)->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class);

    Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);
    expect(fn () => app(TaskVmImageRefreshIntent::class)->start($host, 'test', fromStock: true))
        ->toThrow(ResourceOperationException::class, 'rebuild after it ends');
});

it('has the removal of a group\'s workspace admit the release of its slot and wait for it, once', function (): void {
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Settling])->save();

    expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group))->toThrow(TaskWorkspaceWaiting::class, 'removes the workspace');
    $releases = Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_id', $slot->id)->get();
    expect($releases)->toHaveCount(1)->and($releases->sole()->triggered_by)->toBe('tasks-complete')
        ->and($slot->refresh()->state)->toBe(TaskVmState::Releasing);

    // A second removal waits for that release and admits no other.
    expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group->refresh()))->toThrow(TaskWorkspaceWaiting::class)
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->count())->toBe(1);
});

it('releases a parked task VM as it is: no start before the removal, and no resume', function (): void {
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $slot->update(['state' => TaskVmState::Parked, 'stop_confirmed_at' => now()]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Settling])->save();

    expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group))->toThrow(TaskWorkspaceWaiting::class, 'removes the workspace')
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_id', $slot->id)->count())->toBe(1)
        ->and(Operation::query()->where('kind', 'task_vm.resume')->exists())->toBeFalse()
        ->and($slot->refresh()->state)->toBe(TaskVmState::Releasing);
});

it('releases a VM of the old pool by its name: its topology seed goes, and the hub peer by the Node\'s public key', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld();
    $group = slotPoolGroup($project);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'tvm-610', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.140', 'wireguard_ip' => '10.44.0.140', 'user' => 'orbit', 'wireguard_public_key' => str_repeat('o', 43).'=']));
    // As tvm-610: no slot number and no private key on the Gateway.
    $vm = TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $host->id, 'node_id' => $node->id, 'provider' => TaskVmProviderKind::Incus,
        'size' => TaskVmSize::Medium, 'name' => 'tvm-610', 'state' => TaskVmState::Failed, 'wireguard_ip' => '10.44.0.140']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Settling])->save();

    expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group))->toThrow(TaskWorkspaceWaiting::class, 'removes the workspace');
    $intent = Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_id', $vm->id)->sole()->input['intent'];
    expect($intent['slot'])->toBe(['name' => 'tvm-610', 'seed_clone' => 'tvm-610-topology-seed'])
        ->and($intent['hub_peer']['public_key'])->toBe(str_repeat('o', 43).'=')
        ->and($vm->refresh()->state)->toBe(TaskVmState::Releasing);
});

/** A VM of the old pool, as tvm-610: no slot number and no private key on the Gateway, its Node holding the public key. */
function oldPoolVm(Node $host, ?Task $group = null, string $name = 'tvm-610', TaskVmState $state = TaskVmState::Ready, ?string $publicKey = null): TaskVm
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.140', 'wireguard_ip' => '10.44.0.140', 'user' => 'orbit', 'wireguard_public_key' => $publicKey ?? str_repeat('o', 43).'=']));

    return TaskVm::query()->create(['group_id' => $group?->id, 'host_node_id' => $host->id, 'node_id' => $node->id, 'provider' => TaskVmProviderKind::Incus,
        'size' => TaskVmSize::Medium, 'name' => $name, 'state' => $state, 'wireguard_ip' => '10.44.0.140']);
}

it('has a cancel of an old-pool group carry the release of its VM, so its end deletes the VM and not only its records', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld();
    app(TaskExtensionState::class)->enable();
    $group = slotPoolGroup($project);
    $vm = oldPoolVm($host, $group);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $vm->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Settling])->save();

    $cancel = app(TaskCancelIntent::class)->start($group, 'test', null);

    expect($cancel->input['release']['intent']['slot'])->toBe(['name' => 'tvm-610', 'seed_clone' => 'tvm-610-topology-seed'])
        ->and($cancel->input['release']['intent']['host']['node_id'])->toBe($host->id)
        ->and(app(TaskCancelIntent::class)->input($cancel)['intent']['release'])->toBe($cancel->input['release']);
});

it('has the sweep release an old-pool VM again after its release failed', function (): void {
    [$host, $project] = slotPoolHost();
    $vm = oldPoolVm($host, slotPoolGroup($project), state: TaskVmState::Releasing);
    slotPoolSweepWorld();

    // The failed release frees the VM from its group, so only the sweep can release it again.
    app(TaskVmSlotReleaseProjection::class)->record($vm, false, 'task_vm.slot_release_failed');
    expect($vm->refresh()->group_id)->toBeNull()->and($vm->state)->toBe(TaskVmState::Failed);

    app()->call([new SweepTaskVmHosts, 'handle']);

    $release = Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('triggered_by', 'task-vm-sweep')->sole();
    expect($release->target_id)->toBe($vm->id)->and($release->input['intent']['slot']['seed_clone'])->toBe('tvm-610-topology-seed')
        ->and($vm->refresh()->state)->toBe(TaskVmState::Releasing);
});

it('releases only a task VM name: any other name of a slotless row refuses, whatever base or seed it names', function (string $name): void {
    [$host] = slotPoolHost();
    slotPoolSweepWorld();
    // The host was renamed after a refresh recorded beast-base; a release never takes the shape of a name on trust.
    $host->update(['name' => 'sabre']);
    $vm = oldPoolVm($host->refresh(), name: $name, state: TaskVmState::Failed);

    expect(fn () => app(TaskVmSlotRuns::class)->startRelease($vm, 'test'))->toThrow(ResourceOperationException::class, 'has no task VM name')
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse()
        ->and($vm->refresh()->state)->toBe(TaskVmState::Failed);
})->with(['beast-base', 'beast-base-seed', 'sabre-base', 'sabre-base-seed', 'tvm-610-seed', 'shark-tvm-3', 'research-vm']);

it('releases tvm-<id> of the old pool and <host>-tvm-<slot> of a slot, under the host name it had, each with its own seed clone', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld();
    $old = oldPoolVm($host, name: 'tvm-610', state: TaskVmState::Failed);
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $slot->update(['name' => 'shark-tvm-'.$slot->slot_number, 'group_id' => null, 'state' => TaskVmState::Failed]);

    $runs = app(TaskVmSlotRuns::class);
    expect($runs->startRelease($old, 'test')->input['intent']['slot'])->toBe(['name' => 'tvm-610', 'seed_clone' => 'tvm-610-topology-seed'])
        ->and($runs->startRelease($slot->refresh(), 'test')->input['intent']['slot'])
        ->toBe(['name' => 'shark-tvm-'.$slot->slot_number, 'seed_clone' => 'shark-tvm-'.$slot->slot_number.'-seed']);
});

it('never releases a VM whose host is no task VM host', function (): void {
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    $elsewhere = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'sabre', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.12', 'wireguard_ip' => '10.44.0.12', 'user' => 'orbit']));
    $vm = oldPoolVm($elsewhere, state: TaskVmState::Failed);

    expect(fn () => app(TaskVmSlotRuns::class)->startRelease($vm, 'test'))->toThrow(TaskVmException::class, 'is not a task VM host')
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse();
});

it('refuses the release of a VM whose Node has no WireGuard public key, which names its hub peer', function (): void {
    [$host] = slotPoolHost();
    slotPoolSweepWorld();
    $vm = oldPoolVm($host, state: TaskVmState::Failed, publicKey: '');

    expect(fn () => app(TaskVmSlotRuns::class)->startRelease($vm, 'test'))->toThrow(ResourceOperationException::class, 'has no WireGuard public key')
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse();
});

it('has the sweep leave a VM alone that a refresh started after the sweep selected it', function (): void {
    [$host] = slotPoolHost();
    slotPoolSweepWorld();
    $vm = oldPoolVm($host, state: TaskVmState::Provisioning);
    // The refresh commits between the sweep's first look at the host and its release: right after the sweep reads its candidates.
    $started = false;
    TaskVm::retrieved(function () use (&$started, $host): void {
        if (! $started) {
            $started = true;
            Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
                'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);
        }
    });

    app()->call([new SweepTaskVmHosts, 'handle']);

    expect($started)->toBeTrue()->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse()
        ->and($vm->refresh()->state)->toBe(TaskVmState::Provisioning);
});

/**
 * A group whose workspace sits on its parked task VM, with subtask approvals of `$approved` in order, and a GitHub where `$origin['branch']`
 * lists the commits the group's task branch reaches, its head first; without that key the branch does not exist, and `unreadable` fails
 * the read. Any other key is a commit and the commits it reaches. The test may change `$origin` later. The group's pull request has the head
 * `$merged` and the state `$state`, when given. `$reads` records each read with the token it used.
 *
 * @param  list<string>  $approved
 * @param  array<string,list<string>|string>  $origin
 * @param  list<string>  $reads
 * @return array{0:Task,1:TaskVm,2:Node}
 */
function preservationWorld(array $approved, array &$origin, ?string $merged = null, TaskGroupStatus $status = TaskGroupStatus::Settling,
    GitHubPullRequestState $state = GitHubPullRequestState::Merged, array &$reads = []): array
{
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
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
    $slot = app(TaskVmSlotPool::class)->take($group);
    $slot->update(['state' => TaskVmState::Parked, 'stop_confirmed_at' => now()]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->save();

    return [$group, $slot, Node::query()->where('name', 'gateway')->sole()];
}

/** One `task:complete` or `task:cancel` call from the Gateway Node. */
function preservationCall(Node $gateway, Task $group, string $command): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->withHeader('Idempotency-Key', 'end-'.fake()->uuid())
        ->postJson("/api/v1/tasks/{$group->id}/{$command}");
}

it('refuses task:complete while origin lacks the latest approval, though the approved commit before it merged, and changes nothing', function (): void {
    [$a, $b] = [str_repeat('a', 40), str_repeat('b', 40)];
    // A merged and GitHub deleted the branch; B was approved later and lives only in the parked VM.
    $origin = [$a => [$a]];
    [$group, $slot, $gateway] = preservationWorld([$a, $b], $origin, merged: $a, status: TaskGroupStatus::Running);

    preservationCall($gateway, $group, 'complete')->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished')
        ->assertJsonPath('error.details.commit', $b)->assertJsonPath('error.details.branch', 'task-'.$group->id);

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->watched_pr_completion)->toBeNull()->and($group->taskable_id)->not->toBeNull()
        ->and($slot->refresh()->state)->toBe(TaskVmState::Parked)
        ->and(Operation::query()->whereIn('kind', [TaskVmSlotPool::ReleaseKind, 'task_vm.resume'])->exists())->toBeFalse();

    // task:publish pushed B; now the group completes and its parked VM goes as it is.
    $origin['branch'] = [$b, $a];
    preservationCall($gateway, $group, 'complete')->assertOk();

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)->and($slot->refresh()->state)->toBe(TaskVmState::Releasing)
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_id', $slot->id)->count())->toBe(1)
        ->and(Operation::query()->where('kind', 'task_vm.resume')->exists())->toBeFalse();
});

it('takes the merged pull request\'s head for a deleted task branch, so a merged latest approval completes', function (): void {
    $a = str_repeat('a', 40);
    $origin = [$a => [$a]];
    [$group, $slot, $gateway] = preservationWorld([$a], $origin, merged: $a, status: TaskGroupStatus::Running);

    preservationCall($gateway, $group, 'complete')->assertOk();

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)->and($slot->refresh()->state)->toBe(TaskVmState::Releasing);
});

it('refuses task:cancel while origin lacks the latest approval, admitting nothing, and admits a release with no push once it has it', function (): void {
    [$a, $b] = [str_repeat('a', 40), str_repeat('b', 40)];
    $origin = ['branch' => [$a]];
    [$group, $slot, $gateway] = preservationWorld([$a, $b], $origin);

    preservationCall($gateway, $group, 'cancel')->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished')
        ->assertJsonPath('error.details.commit', $b);
    expect(Operation::query()->exists())->toBeFalse()->and($slot->refresh()->state)->toBe(TaskVmState::Parked);

    $origin['branch'] = [$b, $a];
    preservationCall($gateway, $group, 'cancel')->assertStatus(202);

    $cancel = Operation::query()->where('kind', 'tasks.cancel')->sole();
    expect($cancel->input)->not->toHaveKey('push')->and($cancel->input['release']['intent']['slot']['name'])->toBe($slot->name)
        ->and(Operation::query()->where('kind', 'task_vm.resume')->exists())->toBeFalse();
});

it('refuses the release of a resuming task VM, completes the group with the reason, and releases it once on retries after the resume', function (): void {
    $origin = [];
    [$group, $slot, $gateway] = preservationWorld([], $origin);
    $resume = Operation::query()->create(['kind' => 'task_vm.resume', 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $slot->host_node_id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'task-vm']);

    preservationCall($gateway, $group, 'complete')->assertOk();
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)->and($group->assistance_reason)->toContain('task_vm.resume')
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse()->and($slot->refresh()->state)->toBe(TaskVmState::Parked);

    $resume->update(['status' => OperationStatus::Succeeded]);
    preservationCall($gateway, $group, 'complete')->assertOk();
    preservationCall($gateway, $group, 'complete')->assertOk();

    expect(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_id', $slot->id)->count())->toBe(1)
        ->and($slot->refresh()->state)->toBe(TaskVmState::Releasing);
});

it('reads the task branch and compares with a contents:read token, and the pull request with a pull_requests:read token', function (): void {
    $a = str_repeat('a', 40);
    $origin = ['branch' => [$a]];
    $reads = [];
    [$group, , $gateway] = preservationWorld([$a], $origin, reads: $reads);
    preservationCall($gateway, $group, 'complete')->assertOk();
    expect($reads)->toBe(['branch:contents-read', 'compare:contents-read']);
});

it('reads a merged pull request with its own token when its branch is gone, and compares its head with the contents token', function (): void {
    $a = str_repeat('a', 40);
    $origin = [$a => [$a]];
    $reads = [];
    [$merged, , $gateway] = preservationWorld([$a], $origin, merged: $a, status: TaskGroupStatus::Running, reads: $reads);
    preservationCall($gateway, $merged, 'complete')->assertOk();
    expect($reads)->toContain('branch:contents-read', 'pull:pull-requests-read', 'compare:contents-read')
        ->and($reads)->not->toContain('compare:pull-requests-read', 'branch:pull-requests-read');
});

it('refuses when the task branch cannot be read, though the merged pull request\'s head is the approved commit', function (): void {
    $a = str_repeat('a', 40);
    $origin = ['branch' => 'unreadable', $a => [$a]];
    $reads = [];
    [$group, $slot, $gateway] = preservationWorld([$a], $origin, merged: $a, status: TaskGroupStatus::Running, reads: $reads);

    preservationCall($gateway, $group, 'complete')->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished');
    // The completion reads its watched pull request first; the failed branch read ends the check before any compare.
    expect($reads)->toBe(['pull:pull-requests-read', 'branch:contents-read'])->and($group->refresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($slot->refresh()->state)->toBe(TaskVmState::Parked);
});

it('refuses when the task branch is gone and its pull request closed without a merge, though the head is the approved commit', function (): void {
    $a = str_repeat('a', 40);
    $origin = [$a => [$a]];
    [$group, $slot, $gateway] = preservationWorld([$a], $origin, merged: $a, state: GitHubPullRequestState::Closed);

    preservationCall($gateway, $group, 'complete')->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Settling)->and($slot->refresh()->state)->toBe(TaskVmState::Parked);
});

it('leaves the group\'s open operations untouched when a complete or a cancel refuses', function (string $command): void {
    [$a, $b] = [str_repeat('a', 40), str_repeat('b', 40)];
    $origin = ['branch' => [$a]];
    [$group, $slot, $gateway] = preservationWorld([$a, $b], $origin);
    $turn = Operation::query()->create(['kind' => 'tasks.turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $slot->host_node_id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);

    preservationCall($gateway, $group, $command)->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished');

    expect($turn->refresh()->status)->toBe(OperationStatus::Running)->and($turn->cancel_requested_at)->toBeNull()
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Settling)->and($slot->refresh()->state)->toBe(TaskVmState::Parked);
})->with(['complete', 'cancel']);

it('refuses a cancel while a resume of the group\'s VM is open, cancelling nothing, and admits it after the resume', function (): void {
    $origin = [];
    [$group, $slot, $gateway] = preservationWorld([], $origin);
    $resume = Operation::query()->create(['kind' => 'task_vm.resume', 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $slot->host_node_id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'task-vm']);
    $turn = Operation::query()->create(['kind' => 'tasks.turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $slot->host_node_id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);

    preservationCall($gateway, $group, 'cancel')->assertStatus(409)->assertJsonPath('error.code', 'operation.target_busy')
        ->assertJsonPath('error.details.operation_id', $resume->id);
    expect($turn->refresh()->cancel_requested_at)->toBeNull()->and(Operation::query()->where('kind', 'tasks.cancel')->exists())->toBeFalse()
        ->and($slot->refresh()->state)->toBe(TaskVmState::Parked);

    $resume->update(['status' => OperationStatus::Succeeded]);
    $turn->update(['status' => OperationStatus::Succeeded]);
    preservationCall($gateway, $group, 'cancel')->assertStatus(202);
    expect($slot->refresh()->state)->toBe(TaskVmState::Releasing);
});

it('reserves the VM at cancel admission, so a resume asked for later starts nothing', function (): void {
    $origin = [];
    [$group, $slot, $gateway] = preservationWorld([], $origin);

    preservationCall($gateway, $group, 'cancel')->assertStatus(202);

    expect($slot->refresh()->state)->toBe(TaskVmState::Releasing)
        ->and(app(StartTaskVmResumeAction::class)->execute($slot->id))->toBeNull()
        ->and(app(WakeTaskVmAction::class)->forGroup($group->refresh()))->toBeTrue()
        ->and(Operation::query()->where('kind', 'task_vm.resume')->exists())->toBeFalse();
});

it('frees the VM a cancel reserved when its start is refused, so the next cancel reserves it again and runs', function (): void {
    $origin = [];
    [$group, $slot, $gateway] = preservationWorld([], $origin);
    preservationCall($gateway, $group, 'cancel')->assertStatus(202);
    $refused = Operation::query()->where('kind', 'tasks.cancel')->sole();
    expect($slot->refresh()->state)->toBe(TaskVmState::Releasing);

    // Swamp refused the start: the Operation fails before any run exists.
    $refused->update(['status' => OperationStatus::Failed, 'error_code' => 'swamp.start_refused', 'lock_key' => null, 'finished_at' => now()]);
    app(TaskCancelProjection::class)->refused($refused);

    expect($slot->refresh()->state)->toBe(TaskVmState::Failed)->and($slot->error_code)->toBe('swamp.start_refused')
        ->and($slot->group_id)->toBe($group->id)->and($group->refresh()->status)->toBe(TaskGroupStatus::Settling);

    preservationCall($gateway, $group, 'cancel')->assertStatus(202);
    $retry = Operation::query()->where('kind', 'tasks.cancel')->whereKeyNot($refused->id)->sole();
    expect($retry->input['release']['intent']['slot']['name'])->toBe($slot->name)->and($slot->refresh()->state)->toBe(TaskVmState::Releasing);
});

it('ends a removal failure assistance when the release deletes the group\'s workspace, and leaves other requests alone', function (): void {
    [, $project] = slotPoolHost();
    $pool = app(TaskVmSlotPool::class);
    $workspace = function (Task $group, TaskVm $slot, string $name) use ($project): Instance {
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => $name, 'checkout_path' => '/home/orbit/apps/orbit/'.$name,
            'task_workspace_owner_id' => $group->id, 'branch_override' => $name]);
        $group->taskable()->associate($instance);
        $group->fill(['status' => TaskGroupStatus::Completed])->save();

        return $instance;
    };
    // The removal failed while the VM was parked; the release now deletes the workspace.
    $failed = slotPoolGroup($project);
    $failedSlot = $pool->take($failed);
    $workspace($failed, $failedSlot, 'task-1');
    TaskAssistance::apply($failed, AssistanceKind::RemovalFailure, null, 'Workspace removal failed: the task VM is parked.', replaceFailure: true);
    // A running group asks about something else; its VM is released too, as after a failed start.
    $other = slotPoolGroup($project);
    $otherSlot = $pool->take($other);
    $workspace($other, $otherSlot, 'task-2');
    $other->update(['status' => TaskGroupStatus::Running]);
    TaskAssistance::apply($other, AssistanceKind::Failure, null, 'The check failed.', replaceFailure: true);

    $projection = app(TaskVmSlotReleaseProjection::class);
    foreach ([$failedSlot, $otherSlot] as $slot) {
        $projection->record($slot->refresh(), true, null);
    }

    expect($failed->refresh()->assistance_requested)->toBeFalse()->and($failed->assistance_kind)->toBeNull()->and($failed->taskable_id)->toBeNull()
        ->and($other->refresh()->assistance_kind)->toBe(AssistanceKind::Failure)->and($other->assistance_requested)->toBeTrue();
});

it('allows CI and base refresh on a host with zero task places', function (): void {
    [$host, $project] = slotPoolHost(taskPlaces: 0, ciPlaces: 2);
    $pool = app(TaskVmSlotPool::class);
    expect(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class);
    expect($pool->take(slotPoolGroup($project), proof: true)->capacity_pool)->toBe('ci')
        ->and($pool->take(null, $host->id)->capacity_pool)->toBe('ci');
    expect(fn () => $pool->take(null, $host->id))->toThrow(TaskCapacityException::class);
});

it('records a full task pool as a bounded wait without allocating any identity', function (): void {
    Queue::fake();
    [, $project] = slotPoolHost(taskPlaces: 0);
    slotPoolSweepWorld();
    $group = slotPoolGroup($project);
    $operation = app(TaskProvisionIntent::class)->start($group, 'test', null, str_repeat('a', 40));
    expect($operation->status)->toBe(OperationStatus::Waiting)->and($operation->error_code)->toBeNull()
        ->and(TaskVm::query()->count())->toBe(0)->and($group->refresh()->taskable_id)->toBeNull();
    Queue::assertPushed(AdmitTaskVm::class);
    app(TaskVmAdmission::class)->admit($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting)->and(TaskVm::query()->count())->toBe(0);
    $operation->forceFill(['created_at' => now()->subHours(2)->subSecond()])->save();
    app(ExpireOperationAction::class)->execute($operation);
    expect($operation->refresh()->error_code)->toBe('tasks.capacity_timeout')->and($operation->lock_key)->toBeNull();
});

it('cancels a waiting task admission without later creating a VM', function (): void {
    Queue::fake();
    [, $project] = slotPoolHost(taskPlaces: 0);
    slotPoolSweepWorld();
    $group = slotPoolGroup($project);
    $operation = app(TaskProvisionIntent::class)->start($group, 'test', null, str_repeat('a', 40));
    app(CancelOperationAction::class)->execute($operation);
    app(TaskVmAdmission::class)->admit($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)->and(TaskVm::query()->count())->toBe(0);
});

it('admits the oldest waiting task after a place is released and does not duplicate its claim', function (): void {
    Queue::fake();
    [, $project] = slotPoolHost(taskPlaces: 1);
    slotPoolSweepWorld();
    slotPoolClaimServices();
    $holder = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $group = slotPoolGroup($project);
    $operation = app(TaskProvisionIntent::class)->start($group, 'test', null, str_repeat('a', 40));
    expect($operation->status)->toBe(OperationStatus::Waiting);
    app(TaskVmSlotReleaseProjection::class)->record($holder, true, null);
    app(TaskVmAdmission::class)->admit($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Queued)
        ->and($operation->input)->not->toHaveKey('capacity_request')
        ->and(TaskVm::query()->live()->where('group_id', $group->id)->count())->toBe(1);
    app(TaskVmAdmission::class)->admit($operation->id);
    expect(TaskVm::query()->live()->where('group_id', $group->id)->count())->toBe(1);
});

/** The control services a fresh claim captures; submission is faked by slotPoolSweepWorld. */
function slotPoolClaimServices(): void
{
    $services = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.3', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:services', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'services-public-key']));
    app(AiRouterState::class)->enable($services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    Operation::query()->forceCreate(['kind' => 'ai_router.task_guard', 'target_type' => 'node', 'target_id' => $services->id, 'node_id' => $services->id,
        'status' => OperationStatus::Succeeded, 'input' => ['intent' => ['state' => 'present']]]);
    slotPoolSocket();
    test()->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));
    $home = storage_path('framework/testing/admission-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($home.'/wireguard');
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('h', 32))."\n");
    config(['orbit.home' => $home]);
    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($home));
}

it('claims a fresh VM at the published head for a build or a fix after release', function (bool $fix, bool $releasing): void {
    Queue::fake();
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    slotPoolClaimServices();
    $group = slotPoolGroup($project);
    $group->update(['status' => TaskGroupStatus::WaitingForReview, 'pr_url' => 'https://github.com/hardimpactdev/orbit/pull/1', 'watched_pr_state' => 'open',
        'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra']);
    $group->tasks()->create(['title' => 'Spec', 'brief' => 'Draft', 'position' => 1, 'status' => TaskStatus::Completed,
        'deliverables' => [['id' => 'spec', 'type' => 'review', 'description' => 'The spec is right.']]]);
    Operation::query()->create(['kind' => 'tasks.prep', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => 1,
        'status' => OperationStatus::Succeeded, 'input' => ['command' => 'firewall:deny', 'draft' => ['thread_id' => 999]]]);
    $head = str_repeat('a', 40);
    app()->instance(RemoteBranchHeads::class, new readonly class($head) implements RemoteBranchHeads
    {
        public function __construct(private string $head) {}

        public function head(Project $project, string $branch): string
        {
            return $this->head;
        }
    });
    $previous = $releasing ? app(TaskVmSlotPool::class)->take($group) : null;
    $previous?->update(['state' => TaskVmState::Releasing]);
    $operation = $fix
        ? app(TaskPrepFixIntent::class)->start($group, new StartTaskPrepFixData('Apply the owner review.'), 'test', null)
        : app(TaskBuildIntent::class)->start($group, $head, 'test', null);
    if ($previous !== null) {
        expect($operation->status)->toBe(OperationStatus::Waiting);
        app(TaskVmSlotReleaseProjection::class)->record($previous, true, null);
        app(TaskVmAdmission::class)->admit($operation->id);
        $operation->refresh();
    }
    expect($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->input['provision']['workspace'])->toMatchArray(['start_commit' => $head, 'branch' => 'task-'.$group->id])
        ->and(TaskVm::forGroup($group)?->capacity_pool)->toBe('task')
        ->and($operation->input)->not->toHaveKey('resume');
    $projection = app(DirectOperation::class)->projection($operation);
    $operation->update(['status' => OperationStatus::Failed, 'error_code' => 'swamp.start_refused']);
    $projection->refused($operation);
    Queue::assertPushed(ReleaseTaskVmSlot::class, 1);
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('claims a fresh VM and new author session for a review round at the published head', function (): void {
    Queue::fake();
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    slotPoolClaimServices();
    $head = str_repeat('a', 40);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'gate', 'gate', 'hardimpactdev', 'organization', 'https://github.com/apps/gate', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($head): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReviewsToken', 'repositoryChecksToken')->andReturn('read-token');
        $github->shouldReceive('pullRequest')->andReturn(new GitHubPullRequest(GitHubPullRequestState::Open, true, 'clean', $head, 'main', draft: false, authorLogin: 'orbit-app[bot]'));
        $github->shouldReceive('checkRuns')->andReturn([new GitHubCheckRun('Required checks', 'success', null, status: 'completed', headSha: $head, appSlug: 'github-actions')]);
    }));
    $project->update(['source_access' => 'github_app', 'merge_check' => 'Required checks', 'review_and_merge' => true]);
    $group = slotPoolGroup($project);
    $group->update(['status' => TaskGroupStatus::WaitingForReview, 'review_mode' => TaskReviewMode::Automatic,
        'watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/1', 'watched_pr_number' => 1,
        'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra']);
    $subtask = $group->tasks()->create(['title' => 'Build', 'brief' => 'Build', 'position' => 1, 'status' => TaskStatus::Running,
        'deliverables' => [['id' => 'build', 'type' => 'review', 'description' => 'Build it.']]]);
    $check = TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed,
        'pid' => 1, 'process_started' => 'x', 'head_before' => $head, 'tree_before' => str_repeat('b', 40),
        'head_after' => $head, 'commit_sha' => $head, 'tree_after' => str_repeat('b', 40), 'exit_code' => 0, 'started_at' => now(), 'finished_at' => now()]);
    Operation::query()->create(['kind' => 'tasks.check', 'target_type' => 'task_group', 'target_id' => $group->id,
        'input' => ['subtask' => $subtask->id, 'phase' => 'handoff', 'check_id' => $check->id], 'status' => OperationStatus::Succeeded,
        'result' => ['outcome' => 'passed', 'check_id' => $check->id, 'commit' => $head, 'head' => $head, 'tree' => str_repeat('b', 40),
            'ci' => ['state' => 'passed', 'commit' => $head, 'branch' => TaskRemoteBranch::handoff($group), 'check_name' => 'Required checks']]]);

    $operation = app(TaskReviewRoundIntent::class)->start($group, $head, 'test', null);

    expect($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->input['provision']['workspace'])->toMatchArray(['start_commit' => $head, 'branch' => 'task-'.$group->id])
        ->and($operation->input['fix']['new_session'])->toBeTrue()
        ->and($operation->input['review']['new_session'])->toBeTrue()
        ->and(TaskVm::forGroup($group)?->capacity_pool)->toBe('task');
});

it('rebuilds a normal fix turn from the published branch after deletion, waiting for a task place when needed', function (bool $wait): void {
    Queue::fake();
    [, $project] = slotPoolHost(taskPlaces: 1);
    slotPoolSweepWorld();
    slotPoolClaimServices();
    $group = slotPoolGroup($project);
    $group->update(['implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra']);
    $subtask = $group->tasks()->create(['title' => 'Fix the review', 'brief' => 'Apply the review.', 'position' => 1, 'status' => TaskStatus::Running,
        'deliverables' => [['id' => 'fix', 'type' => 'review', 'description' => 'Review applied.']]]);
    $initial = app(TaskProvisionIntent::class)->start($group, 'test', null, str_repeat('a', 40));
    $initial->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $old = TaskVm::forGroup($group);
    $oldNode = $old->node_id;
    $workspace = $group->fresh()->taskable_id;
    $old->update(['state' => TaskVmState::Releasing]);
    app(TaskVmSlotReleaseProjection::class)->record($old, true, null);
    $group->refresh()->update(['status' => TaskGroupStatus::WaitingForReview, 'pr_url' => 'https://github.com/hardimpactdev/orbit/pull/1']);
    expect(Instance::query()->find($workspace))->toBeNull()->and(Node::query()->find($oldNode))->toBeNull();
    $head = str_repeat('d', 40);
    app()->instance(RemoteBranchHeads::class, new readonly class($head, $group->id) implements RemoteBranchHeads
    {
        public function __construct(private string $head, private int $group) {}

        public function head(Project $project, string $branch): string
        {
            expect($branch)->toBe('task-'.$this->group);

            return $this->head;
        }
    });
    $holder = $wait ? app(TaskVmSlotPool::class)->take(slotPoolGroup($project)) : null;
    $turn = app(TaskTurnIntent::class)->start($group,
        new TaskTurnData($subtask->id, 'implementer', 'Apply the review.', 'new'), 'test', null);
    if ($holder !== null) {
        expect($turn->status)->toBe(OperationStatus::Waiting)->and(TaskVm::forGroup($group))->toBeNull();
        app(TaskVmSlotReleaseProjection::class)->record($holder, true, null);
        app(TaskVmAdmission::class)->admit($turn->id);
        $turn->refresh();
    }
    expect($turn->status)->toBe(OperationStatus::Queued)
        ->and($turn->input['provision']['workspace'])->toMatchArray(['start_commit' => $head, 'branch' => 'task-'.$group->id])
        ->and($turn->input['new_session'])->toBeTrue()
        ->and($turn->input['node_id'])->not->toBe($oldNode)
        ->and(TaskVm::forGroup($group)?->capacity_pool)->toBe('task');
    if ($wait) {
        $turn->update(['status' => OperationStatus::Failed, 'error_code' => 'swamp.start_refused']);
        app(TaskTurnProjection::class)->refused($turn);
        Queue::assertPushed(ReleaseTaskVmSlot::class, 1);
    } else {
        $turn->update(['state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a']]);
        app(TaskTurnProjection::class)->apply($turn, ['run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'status' => 'succeeded', 'result' => [
            'steps' => ['Claim a task place' => ['status' => 'succeeded']],
            'turn' => json_encode(['state' => 'idle', 'subtask' => $subtask->id]),
        ]]);
        expect(TaskVm::forGroup($group)?->state)->toBe(TaskVmState::Ready)
            ->and($group->fresh()->taskable->starting_commit)->toBe($head);
        Queue::assertNotPushed(ReleaseTaskVmSlot::class);
    }
})->with([false, true]);

it('makes an operator proof join the task queue behind an earlier task even when a place has just freed', function (): void {
    Queue::fake();
    [, $project] = slotPoolHost(taskPlaces: 1);
    slotPoolSweepWorld();
    slotPoolClaimServices();
    $holder = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $task = slotPoolGroup($project);
    $earlier = app(TaskProvisionIntent::class)->start($task, 'test', null, str_repeat('a', 40));
    app(TaskVmSlotReleaseProjection::class)->record($holder, true, null);
    $proof = slotPoolGroup($project);
    $later = app(TaskProvisionIntent::class)->admit($proof, str_repeat('a', 40), 'proof.command', static fn (array $input): array => $input, 'test', null);

    expect($earlier->status)->toBe(OperationStatus::Waiting)->and($later->status)->toBe(OperationStatus::Waiting)
        ->and(TaskVm::forGroup($proof))->toBeNull();
    app(TaskVmAdmission::class)->admit($later->id);
    expect($later->refresh()->status)->toBe(OperationStatus::Waiting);
    app(TaskVmAdmission::class)->admit($earlier->id);
    expect($earlier->refresh()->status)->toBe(OperationStatus::Queued)->and(TaskVm::forGroup($task)?->capacity_pool)->toBe('task');
});

/** A trusted four-batch CI proof on the host fixture, without a real Swamp submission. */
function slotPoolCiProofWorld(int $places, bool $casesOnly = false): Node
{
    Queue::fake();
    [$host, , $build] = slotPoolHost(ciPlaces: $places);
    slotPoolSweepWorld();
    slotPoolClaimServices();
    app(TaskExtensionState::class)->enable();
    config(['proof.execution' => true, 'swamp.proof_club_api_key' => 'club-key']);
    $commit = str_repeat('a', 40);
    Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => OperationStatus::Succeeded, 'input' => ['commit' => $commit], 'result' => ['refreshed' => true, 'build_id' => $build->id]]);
    $source = Mockery::mock(ProofCommitSource::class);
    $source->shouldReceive('commitExists')->andReturn(true);
    $source->shouldReceive('branchesWithHead')->andReturn(['feature']);
    $source->shouldReceive('changes')->andReturn(new ProofChangedFiles($commit, [new ProofChangedFile($casesOnly ? 'swamp/proof/firewall/allow.cases.yaml' : 'changed.php', 'modified')], true));
    $source->shouldReceive('proofPaths')->andReturn(['swamp/proof/firewall/allow.cases.yaml']);
    $source->shouldReceive('caseSelection')->andReturnUsing(static fn (string $base, string $head): array => [
        'version' => 1, 'base' => $base, 'head' => $head, 'files' => [[
            'command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml',
            'only' => ['a'], 'reasons' => ['a' => 'changed'],
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

it('keeps selected case ids when an operator proof resumes after waiting for a VM', function (): void {
    slotPoolCiProofWorld(2, casesOnly: true);
    $project = Project::query()->firstOrFail();
    $holder = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $intent = app(ProofRunIntent::class);
    $proof = $intent->start(str_repeat('b', 40), false, 'test', null);
    $intent->admit($proof->id);
    $child = ProofRunSettlement::children($proof->id)->sole();
    expect($child->status)->toBe(OperationStatus::Waiting);
    app(TaskVmSlotReleaseProjection::class)->record($holder, true, null);

    app(TaskVmAdmission::class)->admit($child->id);

    expect($child->refresh()->status)->toBe(OperationStatus::Queued)
        ->and($child->input['assignments'][0]['cases'] ?? null)->toBe(['a']);
    $projected = app(ProofCommandProjection::class)->input($child);
    expect($projected['intent']['verifications'][0]['case_ids'] ?? null)->toBe(['a']);
});

it('lets the front CI proof take every free place up to its batch count and records whole assignments once', function (int $places): void {
    $caller = slotPoolCiProofWorld($places);
    $intent = app(ProofRunIntent::class);
    $proof = $intent->start(str_repeat('a', 40), false, 'test', null, keepFailed: true, authenticatedCaller: $caller);
    $intent->admit($proof->id);
    $intent->admit($proof->id);
    $children = ProofRunSettlement::children($proof->id);
    expect($proof->refresh()->error_code)->toBeNull()->and($children)->toHaveCount(min(4, $places))
        ->and(TaskVm::query()->live()->where('capacity_pool', 'ci')->count())->toBe(min(4, $places));
    $assignments = [];
    foreach ($children as $child) {
        expect($child->input['assignments'])->not->toBeEmpty()->and($child->input)->not->toHaveKey('keep_failed');
        foreach ($child->input['assignments'] as $assignment) {
            $assignments[] = $assignment['batch'];
        }
    }
    sort($assignments);
    expect($assignments)->toBe(['four', 'one', 'three', 'two']);
})->with([1, 2, 6]);

it('holds later CI proofs behind the queue front even before the front has selected cases', function (): void {
    $caller = slotPoolCiProofWorld(2);
    $intent = app(ProofRunIntent::class);
    $first = $intent->start(str_repeat('a', 40), false, 'test', null, authenticatedCaller: $caller);
    $second = $intent->start(str_repeat('a', 40), false, 'test', null, authenticatedCaller: $caller);
    $intent->admit($second->id);
    expect($second->refresh()->status)->toBe(OperationStatus::Running)->and($second->input['runs'])->toBe([])
        ->and($second->state['capacity_wait_reason'])->toContain('earlier CI proof')->and(TaskVm::query()->live()->count())->toBe(0);
    $first->update(['status' => OperationStatus::Cancelled]);
    $intent->admit($second->id);
    expect($second->refresh()->input['runs'])->toHaveCount(2);
});

it('lets task cancellation take precedence over a capacity wait or admitted task holding the group lock', function (bool $waiting): void {
    Queue::fake();
    app(TaskExtensionState::class)->enable();
    [, $project] = slotPoolHost(taskPlaces: 1);
    slotPoolSweepWorld();
    slotPoolClaimServices();
    $holder = $waiting ? app(TaskVmSlotPool::class)->take(slotPoolGroup($project)) : null;
    $group = slotPoolGroup($project);
    $work = app(TaskProvisionIntent::class)->start($group, 'test', null, str_repeat('a', 40));
    if (! $waiting) {
        $work->update(['status' => OperationStatus::Running]);
    }
    expect($work->refresh()->lock_key)->not->toBeNull();

    $cancel = app(TaskCancelIntent::class)->start($group, 'test', null);

    expect($work->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($work->cancel_requested_at)->not->toBeNull()
        ->and($work->lock_key)->toBeNull();
    expect($cancel->kind)->toBe('tasks.cancel')
        ->and($cancel->status)->toBe(OperationStatus::Queued)
        ->and($cancel->lock_key)->not->toBeNull();
    expect($group->refresh()->capacity_wait_reason)->toBeNull();
    if ($waiting) {
        app(TaskVmAdmission::class)->admit($work->id);
        expect($work->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and(TaskVm::forGroup($group))->toBeNull()
            ->and($cancel->input['release'])->toBeNull()
            ->and($holder?->refresh()->state)->not->toBe(TaskVmState::Destroyed);
    } else {
        expect(TaskVm::forGroup($group)?->state)->toBe(TaskVmState::Releasing)
            ->and($cancel->input['release'])->not->toBeNull();
    }
})->with([true, false]);

/**
 * The slot pool host with a container side of `$containerTask` task and `$containerCi` CI places, normal placement of
 * containers switched by `$placement`, and a promoted container base unless `$containerBase` is false.
 *
 * @return array{Node, Project}
 */
function containerPoolHost(bool $placement, bool $containerBase = true, int $taskPlaces = 0, int $ciPlaces = 0, int $containerTask = 1, int $containerCi = 1): array
{
    [$host, $project] = slotPoolHost($taskPlaces, ciPlaces: $ciPlaces);
    $settings = app(TaskVmSettings::class);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $settings->devClusterId, $settings->wireguardRange,
        [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', $taskPlaces, 4, '8GiB', '20GiB', ciPlaces: $ciPlaces,
            containers: new TaskVmContainers($containerTask, $containerCi, 'orbit-task-containers', 'orbittaskc0', '10.252.8.0/24'))],
        '/pi', str_repeat('a', 64), ['gpt-6'], containerPlacement: $placement));
    if ($containerBase) {
        TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'runtime' => TaskVmRuntime::Container, 'recipe_identity' => 'recipe-1',
            'state' => TaskVmImageBuildState::Succeeded, 'promoted_at' => now()->addMinute(),
            'images' => ['base' => ['project' => 'orbit-task-containers', 'type' => 'snapshot', 'vm' => 'beast-base', 'snapshot' => 'c20261109120000']]]);
    }

    return [$host, $project];
}

it('skips container places until the switch', function (): void {
    [, $project] = containerPoolHost(placement: false);
    $pool = app(TaskVmSlotPool::class);

    expect(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class)
        ->and(fn () => $pool->take(slotPoolGroup($project), proof: true))->toThrow(TaskCapacityException::class)
        ->and(fn () => $pool->freeCiPlaces())->toThrow(TaskCapacityException::class);
});

it('takes container places after the switch on a host with a container base, and records their runtime', function (): void {
    [, $project] = containerPoolHost(placement: true);
    $pool = app(TaskVmSlotPool::class);
    expect($pool->freeCiPlaces())->toBe(1);

    $task = $pool->take(slotPoolGroup($project));
    $ci = $pool->take(slotPoolGroup($project), proof: true);
    expect([$task->refresh()->runtime, $task->capacity_pool, $ci->refresh()->runtime, $ci->capacity_pool])->toBe([TaskVmRuntime::Container, 'task', TaskVmRuntime::Container, 'ci'])
        ->and(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class);
});

it('takes no container place after the switch on a host without a container base', function (): void {
    [, $project] = containerPoolHost(placement: true, containerBase: false);

    expect(fn () => app(TaskVmSlotPool::class)->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class);
});

it('counts container places apart from VM places', function (): void {
    [$host, $project] = containerPoolHost(placement: true, taskPlaces: 1);
    $pool = app(TaskVmSlotPool::class);
    $location = app(TaskVmSettings::class)->host($host->id);
    $free = static fn (TaskVmRuntime $runtime): int => app(TaskVmCapacity::class)->freeSlots([[$location, $host]], false, $runtime)[$host->id];

    // Equal room keeps the runtime order, so the VM place goes first.
    expect($pool->take(slotPoolGroup($project))->runtime)->toBe(TaskVmRuntime::Vm)
        ->and([$free(TaskVmRuntime::Vm), $free(TaskVmRuntime::Container)])->toBe([0, 1])
        ->and($pool->take(slotPoolGroup($project))->runtime)->toBe(TaskVmRuntime::Container)
        ->and([$free(TaskVmRuntime::Vm), $free(TaskVmRuntime::Container)])->toBe([0, 0])
        ->and(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class);
});

it('has a base refresh take a VM CI place, never a container one', function (): void {
    [$host] = containerPoolHost(placement: true, ciPlaces: 1);
    $pool = app(TaskVmSlotPool::class);

    expect($pool->take(null, $host->id)->runtime)->toBe(TaskVmRuntime::Vm)
        ->and(fn () => $pool->take(null, $host->id))->toThrow(TaskCapacityException::class);
});

it('keeps one recorded base per runtime, so a newer container base never replaces the VM base', function (): void {
    [$host] = containerPoolHost(placement: true);
    $location = app(TaskVmSettings::class)->host($host->id);
    $images = app(TaskVmPromotedImage::class);

    expect($images->recorded($host, $location, TaskVmRuntime::Vm)['snapshot'] ?? null)->toBe('r20261107120000')
        ->and($images->recorded($host, $location, TaskVmRuntime::Container)['snapshot'] ?? null)->toBe('c20261109120000')
        ->and(app(TaskVmSlotPool::class)->currentImage($host, $location)['snapshot'] ?? null)->toBe('r20261107120000');

    // A rebuild of one runtime's base from stock withdraws only that base.
    $images->withdraw($host, TaskVmRuntime::Vm);
    expect($images->recorded($host, $location, TaskVmRuntime::Vm))->toBeNull()
        ->and($images->recorded($host, $location, TaskVmRuntime::Container)['snapshot'] ?? null)->toBe('c20261109120000');
});

/** One Operation on the group that ended this many minutes ago, or that is still open when $open is set. */
function idleGroupOperation(Task $group, TaskVm $slot, int $minutesAgo, bool $open = false, string $kind = 'tasks.build'): Operation
{
    $at = now()->subMinutes($minutesAgo);

    return Operation::query()->forceCreate(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $slot->host_node_id,
        'status' => $open ? OperationStatus::Running : ($kind === TaskIdleSave::Kind ? OperationStatus::Failed : OperationStatus::Succeeded), 'input' => [], 'triggered_by' => 'test',
        'created_at' => $at, 'updated_at' => $at, 'started_at' => $at, 'finished_at' => $open ? null : $at]);
}

/** Origin's task branch as the idle release reads it: its head, or unreadable when null. */
function idleTaskBranch(?string $head): void
{
    app()->instance(RemoteBranchHeads::class, new readonly class($head) implements RemoteBranchHeads
    {
        public function __construct(private ?string $head) {}

        public function head(Project $project, string $branch): string
        {
            return $this->head ?? throw new ResourceOperationException('source.head_unavailable', 'No such branch.', 502);
        }
    });
}

/** @return array{0: Task, 1: TaskVm} an open group whose ready VM ran its last Operation two hours ago, with approved commit A on origin */
function idleWorld(TaskGroupStatus $status = TaskGroupStatus::Running): array
{
    $a = str_repeat('a', 40);
    $origin = ['branch' => [$a]];
    [$group, $slot] = preservationWorld([$a], $origin, status: $status);
    $slot->update(['state' => TaskVmState::Ready]);
    idleTaskBranch($a);

    return [$group, $slot];
}

/** @return Builder<Operation> */
function idleOperations(Task $group, TaskVm $slot): Builder
{
    return Operation::query()->where(fn ($query) => $query->where(fn ($save) => $save->where('kind', TaskIdleSave::Kind)->where('target_id', $group->id)->where('status', '!=', OperationStatus::Failed))
        ->orWhere(fn ($release) => $release->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_id', $slot->id)));
}

/** The terminal event of the group's idle save run, as the Gateway reads it from Swamp. */
function idleSaveEnds(Operation $save, string $status, ?string $saved): Operation
{
    $save->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a']]);

    return app(TaskIdleSaveProjection::class)->apply($save, ['run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => $status,
        'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => $saved === null ? [] : ['saved' => $saved."\n"]]);
}

it('saves the workspace of an open group that ran no Operation for 30 minutes before anything releases its VM', function (): void {
    [$group, $slot] = idleWorld();
    idleGroupOperation($group, $slot, TaskVmIdleRelease::IdleMinutes + 1);

    expect(app(TaskVmIdleRelease::class)->release())->toBe([$slot->id])->and($slot->refresh()->state)->toBe(TaskVmState::Ready);
    $save = Operation::query()->where('kind', TaskIdleSave::Kind)->sole();
    expect($save->target_id)->toBe($group->id)->and($save->triggered_by)->toBe('task-vm-idle')
        ->and($save->input)->toMatchArray(['task_vm_id' => $slot->id, 'save_branch' => 'task-'.$group->id.'-idle',
            'refspec' => '+refs/heads/candidate:refs/heads/task-'.$group->id.'-idle', 'remote' => 'https://github.com/hardimpactdev/orbit.git', 'object_format' => 'sha1'])
        ->and($save->input['execution_secrets']['git_authorization']['key'])->toStartWith('task-publish-token-')
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse()
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Running);

    // The save holds the group, so the next pass admits nothing more.
    expect(app(TaskVmIdleRelease::class)->release())->toBe([])->and(Operation::query()->where('kind', TaskIdleSave::Kind)->count())->toBe(1);
});

it('records the saved commit and admits the release once the save passed', function (): void {
    [$group, $slot] = idleWorld();
    idleGroupOperation($group, $slot, 120);
    app(TaskVmIdleRelease::class)->release();
    $saved = str_repeat('c', 40);

    $projected = idleSaveEnds(Operation::query()->where('kind', TaskIdleSave::Kind)->sole(), 'succeeded', $saved);

    expect($projected->status)->toBe(OperationStatus::Succeeded)->and($projected->result)->toBe(['saved_commit' => $saved, 'branch' => 'task-'.$group->id.'-idle', 'released' => true])
        ->and($group->refresh()->idle_saved_commit)->toBe($saved)->and($group->idle_saved_at)->not->toBeNull()
        ->and($group->status)->toBe(TaskGroupStatus::Running)
        ->and($slot->refresh()->state)->toBe(TaskVmState::Releasing)
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_id', $slot->id)->sole()->triggered_by)->toBe('task-vm-idle');
});

it('keeps the VM when the save failed, and saves again at the next pass', function (): void {
    [$group, $slot] = idleWorld();
    idleGroupOperation($group, $slot, 120);
    app(TaskVmIdleRelease::class)->release();

    $projected = idleSaveEnds(Operation::query()->where('kind', TaskIdleSave::Kind)->sole(), 'failed', null);

    expect($projected->status)->toBe(OperationStatus::Failed)->and($projected->error_code)->toBe('tasks.idle_save_failed')
        ->and($group->refresh()->idle_saved_commit)->toBeNull()->and($slot->refresh()->state)->toBe(TaskVmState::Ready)
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse();

    // The failed save is no activity of the group: the next pass tries again at once.
    expect(app(TaskVmIdleRelease::class)->release())->toBe([$slot->id])
        ->and(Operation::query()->where('kind', TaskIdleSave::Kind)->count())->toBe(2);
});

it('records a passed save but keeps the VM while a resume of it is open', function (): void {
    [$group, $slot] = idleWorld();
    idleGroupOperation($group, $slot, 120);
    app(TaskVmIdleRelease::class)->release();
    Operation::query()->create(['kind' => 'task_vm.resume', 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $slot->host_node_id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'task-vm']);

    $projected = idleSaveEnds(Operation::query()->where('kind', TaskIdleSave::Kind)->sole(), 'succeeded', str_repeat('c', 40));

    expect($projected->result['released'])->toBeFalse()->and($group->refresh()->idle_saved_commit)->toBe(str_repeat('c', 40))
        ->and($slot->refresh()->state)->toBe(TaskVmState::Ready)->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse();
});

it('saves nothing for a group that worked within 30 minutes, has an open Operation, has none at all, or ended', function (string $case): void {
    [$group, $slot] = idleWorld($case === 'ended' ? TaskGroupStatus::Completed : TaskGroupStatus::Running);
    if ($case !== 'none') {
        idleGroupOperation($group, $slot, $case === 'recent' ? TaskVmIdleRelease::IdleMinutes - 1 : 120);
    }
    if ($case === 'open') {
        idleGroupOperation($group, $slot, 120, open: true);
    }

    expect(app(TaskVmIdleRelease::class)->release())->toBe([])->and($slot->refresh()->state)->toBe(TaskVmState::Ready)->and(idleOperations($group, $slot)->count())->toBe(0);
})->with(['recent', 'open', 'none', 'ended']);

it('saves nothing for an idle parked VM, which the save cannot reach, and saves it once it runs', function (): void {
    [$group, $slot] = idleWorld();
    $slot->update(['state' => TaskVmState::Parked, 'stop_confirmed_at' => now()]);
    idleGroupOperation($group, $slot, 120);

    // The pass asks for the resume (TaskVmAwake); this fixture's guest cannot resume, which keeps the VM as well.
    expect(app(TaskVmIdleRelease::class)->release())->toBe([])->and(Operation::query()->where('kind', TaskIdleSave::Kind)->exists())->toBeFalse();

    $slot->update(['state' => TaskVmState::Ready]);
    expect(app(TaskVmIdleRelease::class)->release())->toBe([$slot->id]);
});

it('saves nothing while a resume of the VM is open', function (): void {
    [$group, $slot] = idleWorld();
    idleGroupOperation($group, $slot, 120);
    Operation::query()->create(['kind' => 'task_vm.resume', 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $slot->host_node_id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'task-vm']);

    expect(app(TaskVmIdleRelease::class)->release())->toBe([])->and(idleOperations($group, $slot)->count())->toBe(0);
});

it('saves nothing while origin has no task branch to restore', function (): void {
    [$group, $slot] = idleWorld();
    idleGroupOperation($group, $slot, 120);
    idleTaskBranch(null);

    expect(app(TaskVmIdleRelease::class)->release())->toBe([])->and(idleOperations($group, $slot)->count())->toBe(0);
});

it('saves nothing while origin lacks the group\'s latest approved commit, and saves once publication put it there', function (): void {
    [$a, $b] = [str_repeat('a', 40), str_repeat('b', 40)];
    $origin = ['branch' => [$a]];
    [$group, $slot] = preservationWorld([$a, $b], $origin, status: TaskGroupStatus::Running);
    $slot->update(['state' => TaskVmState::Ready]);
    idleTaskBranch($a);
    idleGroupOperation($group, $slot, 120);

    expect(app(TaskVmIdleRelease::class)->release())->toBe([]);

    $origin['branch'] = [$b, $a];
    expect(app(TaskVmIdleRelease::class)->release())->toBe([$slot->id]);
});

it('leaves CI proof VMs to their proof', function (): void {
    [$group, $slot] = idleWorld(TaskGroupStatus::Reserved);
    $slot->update(['capacity_pool' => 'ci']);
    idleGroupOperation($group, $slot, 120);

    expect(app(TaskVmIdleRelease::class)->release())->toBe([])->and(idleOperations($group, $slot)->count())->toBe(0);
});

/**
 * A group with an idle save, and a GitHub that compares the task branch head with it as `$status`, or fails when null, and whose save
 * branch exists unless `$branch` is false.
 */
function idleRestoreWorld(?GitHubComparisonStatus $status, bool $branch = true): Task
{
    [, $project] = slotPoolHost();
    $head = str_repeat('a', 40);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'read', 'read', 'hardimpactdev', 'organization', 'https://github.com/apps/read', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($status, $head, $branch): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReadToken')->andReturn('contents-read');
        $github->shouldReceive('branchHead')->withArgs(fn (string $token, GitHubRepository $repository, string $name): bool => str_ends_with($name, '-idle'))
            ->andReturn($branch ? str_repeat('c', 40) : null);
        $compare = $github->shouldReceive('compareCommits')->withArgs(fn (string $token, GitHubRepository $repository, string $base): bool => $token === 'contents-read' && $base === $head);
        $status === null ? $compare->andThrow(GitHubApiException::unavailable())
            : $compare->andReturn(new GitHubCommitComparison($status, $head, $status === GitHubComparisonStatus::Diverged ? str_repeat('0', 40) : $head));
    }));

    return Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running,
        'idle_saved_commit' => str_repeat('c', 40)])->load('project');
}

it('starts a turn or a fix from an idle save ahead of the task branch, and a round or a build refuses it', function (bool $continue): void {
    $group = idleRestoreWorld(GitHubComparisonStatus::Ahead);

    if ($continue) {
        expect(app(TaskIdleSave::class)->restore($group, str_repeat('a', 40), true))->toBe(str_repeat('c', 40));
    } else {
        expect(fn () => app(TaskIdleSave::class)->restore($group, str_repeat('a', 40), false))
            ->toThrow(fn (ResourceOperationException $refused) => expect([$refused->errorCode, $refused->details['branch']])->toBe(['tasks.idle_save_pending', 'task-'.$group->id.'-idle']));
    }
})->with(['a turn' => [true], 'a review round' => [false]]);

it('starts every claim from the task branch once it holds the idle save', function (GitHubComparisonStatus $status, bool $continue): void {
    $group = idleRestoreWorld($status);

    expect(app(TaskIdleSave::class)->restore($group, str_repeat('a', 40), $continue))->toBe(str_repeat('a', 40));
})->with([[GitHubComparisonStatus::Behind, true], [GitHubComparisonStatus::Behind, false], [GitHubComparisonStatus::Identical, true]]);

it('refuses a claim while the task branch moved away from the idle save or the comparison cannot be read', function (?GitHubComparisonStatus $status, string $code): void {
    $group = idleRestoreWorld($status);

    expect(fn () => app(TaskIdleSave::class)->restore($group, str_repeat('a', 40), true))
        ->toThrow(fn (ResourceOperationException $refused) => expect($refused->errorCode)->toBe($code));
    // The saved commit itself needs no comparison.
    expect(app(TaskIdleSave::class)->restore($group, str_repeat('c', 40), true))->toBe(str_repeat('c', 40));
})->with([[GitHubComparisonStatus::Diverged, 'tasks.idle_save_diverged'], [null, 'tasks.idle_save_unreadable']]);

it('treats a deleted save branch as no save once the task branch holds its commit, and refuses with the way out otherwise', function (?GitHubComparisonStatus $status): void {
    $group = idleRestoreWorld($status, branch: false);

    if ($status === GitHubComparisonStatus::Behind) {
        expect(app(TaskIdleSave::class)->restore($group, str_repeat('a', 40), true))->toBe(str_repeat('a', 40));

        return;
    }
    expect(fn () => app(TaskIdleSave::class)->restore($group, str_repeat('a', 40), true))
        ->toThrow(fn (ResourceOperationException $refused) => expect([$refused->errorCode, $refused->getMessage(), $refused->details['saved_commit']])
            ->toBe(['tasks.idle_save_missing', 'The save branch task-'.$group->id.'-idle is gone and the task branch does not hold its commit '.str_repeat('c', 40)
                .'. Push '.str_repeat('c', 40).' to task-'.$group->id.'-idle again, or merge it into the task branch, then try again. Cancel the group when that commit is lost.', str_repeat('c', 40)]));
})->with(['the task branch holds it' => [GitHubComparisonStatus::Behind], 'the save is ahead' => [GitHubComparisonStatus::Ahead], 'the commit is gone' => [null]]);

it('counts a save that reports success without a valid saved commit as failed, and releases nothing', function (?string $saved): void {
    [$group, $slot] = idleWorld();
    idleGroupOperation($group, $slot, 120);
    app(TaskVmIdleRelease::class)->release();

    $projected = idleSaveEnds(Operation::query()->where('kind', TaskIdleSave::Kind)->sole(), 'succeeded', $saved);

    expect($projected->status)->toBe(OperationStatus::Failed)->and($projected->error_code)->toBe('tasks.idle_save_failed')
        ->and($group->refresh()->idle_saved_commit)->toBeNull()->and($slot->refresh()->state)->toBe(TaskVmState::Ready)
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse();
})->with(['no commit' => [null], 'an empty commit' => [''], 'not a commit' => ['refs/heads/candidate']]);

it('stops saving at once and asks for assistance when GitHub refuses a save that changes a workflow', function (): void {
    [$group, $slot] = idleWorld();
    idleGroupOperation($group, $slot, 120);
    app(TaskVmIdleRelease::class)->release();
    $save = Operation::query()->where('kind', TaskIdleSave::Kind)->sole();
    $save->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a']]);

    $projected = app(TaskIdleSaveProjection::class)->apply($save, ['run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed',
        'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => [],
        'failure' => ['failed_step' => 'Read push result', 'detail' => 'tasks.workflows_permission']]);

    expect($projected->error_code)->toBe('tasks.workflows_permission')
        ->and($group->refresh()->assistance_kind)->toBe(AssistanceKind::Failure)->and($group->assistance_reason)->toContain('changes a GitHub workflow')
        ->and($slot->refresh()->state)->toBe(TaskVmState::Ready)
        ->and(app(TaskVmIdleRelease::class)->release())->toBe([]);
});

it('counts a cancelled or interrupted save toward the bound', function (OperationStatus $status): void {
    [$group, $slot] = idleWorld();
    idleGroupOperation($group, $slot, 120);
    foreach (range(1, TaskIdleSave::MaxFailures) as $attempt) {
        idleGroupOperation($group, $slot, 60 - $attempt, kind: TaskIdleSave::Kind)->update(['status' => $status]);
    }

    expect(app(TaskVmIdleRelease::class)->release())->toBe([]);
})->with([OperationStatus::Cancelled, OperationStatus::Interrupted]);

it('stops saving after three failed saves in a row, asks for assistance, and saves again after the driver\'s next Operation', function (): void {
    [$group, $slot] = idleWorld();
    idleGroupOperation($group, $slot, 120);
    foreach (range(1, TaskIdleSave::MaxFailures) as $attempt) {
        expect(app(TaskVmIdleRelease::class)->release())->toBe([$slot->id]);
        idleSaveEnds(Operation::query()->where('kind', TaskIdleSave::Kind)->where('status', OperationStatus::Queued)->sole(), 'failed', null);
    }

    expect($group->refresh()->assistance_requested)->toBeTrue()->and($group->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->assistance_reason)->toContain('failed 3 times in a row')
        ->and(app(TaskVmIdleRelease::class)->release())->toBe([]);

    idleGroupOperation($group, $slot, 60);
    expect(app(TaskVmIdleRelease::class)->release())->toBe([$slot->id]);
});

it('claims a fresh VM at the idle save for a fix after the idle release, and a build refuses it', function (bool $fix): void {
    Queue::fake();
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    slotPoolClaimServices();
    [$head, $saved] = [str_repeat('a', 40), str_repeat('c', 40)];
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'read', 'read', 'hardimpactdev', 'organization', 'https://github.com/apps/read', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($head): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReadToken')->andReturn('contents-read');
        $github->shouldReceive('branchHead')->andReturn(str_repeat('c', 40));
        $github->shouldReceive('compareCommits')->andReturn(new GitHubCommitComparison(GitHubComparisonStatus::Ahead, $head, $head));
    }));
    idleTaskBranch($head);
    $group = slotPoolGroup($project);
    $group->update(['status' => TaskGroupStatus::WaitingForReview, 'pr_url' => 'https://github.com/hardimpactdev/orbit/pull/1', 'watched_pr_state' => 'open',
        'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra', 'idle_saved_commit' => $saved]);
    $group->tasks()->create(['title' => 'Spec', 'brief' => 'Draft', 'position' => 1, 'status' => TaskStatus::Completed,
        'deliverables' => [['id' => 'spec', 'type' => 'review', 'description' => 'The spec is right.']]]);
    Operation::query()->create(['kind' => 'tasks.prep', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => 1,
        'status' => OperationStatus::Succeeded, 'input' => ['command' => 'firewall:deny', 'draft' => ['thread_id' => 999]]]);

    if (! $fix) {
        expect(fn () => app(TaskBuildIntent::class)->start($group, $head, 'test', null))
            ->toThrow(fn (ResourceOperationException $refused) => expect($refused->errorCode)->toBe('tasks.idle_save_pending'));
        expect(TaskVm::forGroup($group))->toBeNull();

        return;
    }
    $operation = app(TaskPrepFixIntent::class)->start($group, new StartTaskPrepFixData('Apply the owner review.'), 'test', null);

    expect($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->input['provision']['workspace'])->toMatchArray(['start_commit' => $saved, 'branch' => 'task-'.$group->id])
        // The VM's converge checks the task branch out at the saved commit.
        ->and(data_get($operation->input, 'provision.intent.converge.checkout'))->toMatchArray(['commit' => $saved, 'branch' => 'task-'.$group->id]);
})->with(['a fix' => [true], 'a build' => [false]]);

it('schedules the idle release every five minutes', function (): void {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('/\*\/5\s+\* \* \* \*\s+'.preg_quote(ReleaseIdleTaskVms::class, '/').'/');
});
