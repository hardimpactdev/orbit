<?php

declare(strict_types=1);

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\RoleName;
use App\Domain\Problems\ProblemRecovery;
use App\Domain\Problems\ProblemSource;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmError;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/*
 * The Gateway reads no task VM row any more (docs/reference/old-paths.md#the-task-vm-readers), and the task VM tables are dropped
 * (docs/reference/old-paths.md#the-task-vm-tables). Each test shows that a Node that a removed guard used to check is treated as
 * every other Node.
 */

function readerNode(string $name, string $address, LifecycleStatus $status = LifecycleStatus::Active): Node
{
    return Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => $name, 'public_ssh_host' => $address, 'wireguard_ip' => $address,
        'user' => 'orbit', 'status' => $status, 'platform' => 'linux', 'architecture' => 'x86_64']));
}

it('records an access grant from a Node with no task VM check', function (): void {
    $consumer = readerNode('consumer', '10.44.0.141');
    $serving = readerNode('serving', '10.44.0.142');

    NodeAccess::query()->create(['consumer_node_id' => $consumer->id, 'serving_node_id' => $serving->id]);

    expect(NodeAccess::query()->where('consumer_node_id', $consumer->id)->count())->toBe(1);
});

it('saves any Instance on a Node with no task VM check', function (): void {
    $node = readerNode('guest', '10.44.0.143');
    $project = Project::query()->create(['name' => 'site', 'slug' => 'site', 'repository_url' => 'https://github.com/acme/site.git', 'default_branch' => 'main', 'root' => 'public']);

    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'free', 'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/home/orbit/apps/site/free', 'status' => InstanceState::Reserved]);

    expect($instance->exists)->toBeTrue();
});

it('gives no sandbox reason to a Node', function (): void {
    $node = readerNode('guest', '10.44.0.144', LifecycleStatus::Failed);

    expect(app(FleetRolloutMembership::class)->exclusion($node))->toBe('inactive');
});

it('reports no parked task VM in Doctor', function (): void {
    $node = readerNode('guest', '10.44.0.145');

    $report = json_encode(app(FactDoctor::class)->report([$node], [DoctorFamily::Node])->toArray(), JSON_THROW_ON_ERROR);

    expect($report)->not->toContain('node.task_vm_parked');
});

it('plans a Node removal with task_vm_id null and task_vm_guest false', function (): void {
    SwampNodeFixture::ensure()->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $node = readerNode('guest', '10.44.0.146');
    $keys = app(SshKeyProvider::class);
    @mkdir(dirname($keys->privateKeyPath()), 0o700, true);
    file_put_contents($keys->privateKeyPath().'.pub', 'ssh-ed25519 AAAAtest gateway');

    $snapshot = app(NodeRemovalPlan::class)->build($node, offline: true, machineDeleted: true);
    $intent = app(NodeRemovalInput::class)->capture($snapshot);

    expect($snapshot['task_vm_id'])->toBeNull()
        ->and(data_get($intent, 'intent.task_vm_guest'))->toBeFalse();
});

it('refuses the removal of an owned workspace that is no task copy with the one 503 operation.kind_unavailable, and records it as a removal failure', function (): void {
    $node = readerNode('guest', '10.44.0.147');
    $project = Project::query()->create(['name' => 'site', 'slug' => 'site', 'repository_url' => 'https://github.com/acme/site.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id,
        'source_layout' => InstanceSourceLayout::Checkout, 'checkout_path' => '/home/orbit/apps/site/workspace', 'task_workspace_owner_id' => $group->id,
        'status' => InstanceState::SourceResolved]);
    $removal = app(RemoveTaskWorkspaceAction::class);

    $refusal = null;
    try {
        $removal->remove($group);
    } catch (ResourceOperationException $exception) {
        $refusal = $exception;
    }
    expect($refusal)->not->toBeNull()->errorCode->toBe('operation.kind_unavailable')->status->toBe(503)
        ->and($refusal->getMessage())->toBe('This workspace is no task copy. Task VMs and their release are removed, so Orbit cannot remove it.');

    $removal->recordFailure($group, $refusal);

    expect($group->refresh()->assistance_kind)->toBe(AssistanceKind::RemovalFailure)
        ->and($group->assistance_reason)->toBe('Workspace removal failed: This workspace is no task copy. Task VMs and their release are removed, so Orbit cannot remove it.')
        ->and(Instance::query()->find($workspace->id))->not->toBeNull()
        // The refusal of the removed SSH guard is gone from the task VM error codes.
        ->and(TaskVmError::tryFrom('task_vm.workspace_mismatch'))->toBeNull();
});

it('recovers an old open recipe-image alert as resource_gone', function (): void {
    $problem = static fn (int $id): ProblemFingerprint => new ProblemFingerprint(['source' => ProblemSource::Release, 'filed_at' => now()->subHour(), 'evidence' => [
        'release_kind' => 'build_failed', 'incident_resource' => 'recipe-image:'.$id, 'incident_attempt' => '2', 'incident_fault' => 'build_failed']]);

    expect(app(ProblemRecovery::class)->reason($problem(1)))->toBe('resource_gone')
        ->and(app(ProblemRecovery::class)->reason($problem(2)))->toBe('resource_gone');
});
