<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofVerifyIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

function verifyRefused(callable $callback): ResourceOperationException
{
    try {
        $callback();
    } catch (ResourceOperationException $exception) {
        return $exception;
    }
    throw new LogicException('Expected a refusal.');
}

/** Admits the proof; the run start after the commit needs a Swamp server the suite has not, and the Operation is recorded before it. */
function verifyStart(Node $guest): void
{
    try {
        app(ProofVerifyIntent::class)->start($guest, 'firewall:allow', null, 'tvm-700', null);
    } catch (ResourceOperationException $error) {
        if (! str_starts_with($error->errorCode, 'swamp.')) {
            throw $error;
        }
    }
}

/** A task VM with its group and workspace: returns the guest Node, which is the caller of `orbit proof`. */
function verifyVm(int $projectId, string $checkout = '/home/orbit/apps/orbit/workspace'): Node
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $guest = $node('tvm-700', '10.44.0.150');
    $group = Task::query()->create(['project_id' => $projectId, 'title' => 'Work', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
    TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-700',
        'state' => TaskVmState::Ready, 'address' => '10.251.78.50', 'wireguard_ip' => '10.44.0.150', 'size' => TaskVmSize::Normal, 'image_fingerprint' => 'fingerprint', 'recipe_identity' => 'recipe']);
    $instance = Instance::query()->create(['project_id' => $projectId, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => $checkout, 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Active, 'first_setup_pending' => false]);
    $group->taskable()->associate($instance)->save();

    return $guest;
}

describe('orbit proof admission', function (): void {
    it('refuses a caller that is not a task VM, and records nothing', function (): void {
        $other = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'mini', 'status' => 'active', 'platform' => 'macos', 'architecture' => 'arm64',
            'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'nckrtl']));

        $refusal = verifyRefused(fn () => app(ProofVerifyIntent::class)->start($other, 'firewall:allow', null, 'mini', null));

        expect($refusal->errorCode)->toBe('proof.no_task_vm')->and(Operation::query()->count())->toBe(0);
    });

    it('records the intent of a named group for a Node with Gateway access, on that group\'s VM', function (): void {
        $orbit = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
        config(['proof.project' => $orbit->id]);
        $guest = verifyVm($orbit->id);
        $group = Task::topLevel()->sole();
        $mini = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'mini', 'status' => 'active', 'platform' => 'macos', 'architecture' => 'arm64',
            'public_ssh_host' => '10.44.0.12', 'wireguard_ip' => '10.44.0.12', 'user' => 'nckrtl']));

        try {
            app(ProofVerifyIntent::class)->start($mini, 'firewall:allow', $group->id, 'mini', null);
        } catch (ResourceOperationException $error) {
            if (! str_starts_with($error->errorCode, 'swamp.')) {
                throw $error;
            }
        }

        $operation = Operation::query()->where('kind', 'proof.verify')->sole();
        expect($operation->target_id)->toBe($group->id)->and($operation->node_id)->toBe($guest->id)
            ->and($operation->input['ssh_execution']['target']['node_id'])->toBe($guest->id);
    });

    it('refuses a named group that does not exist, and records nothing', function (): void {
        $mini = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'mini', 'status' => 'active', 'platform' => 'macos', 'architecture' => 'arm64',
            'public_ssh_host' => '10.44.0.12', 'wireguard_ip' => '10.44.0.12', 'user' => 'nckrtl']));

        $refusal = verifyRefused(fn () => app(ProofVerifyIntent::class)->start($mini, 'firewall:allow', 999, 'mini', null));

        expect($refusal->errorCode)->toBe('tasks.group_missing')->and($refusal->status)->toBe(404)->and(Operation::query()->count())->toBe(0);
    });

    it('refuses a task VM of another Project: the cases exist only for the Orbit repository', function (): void {
        $orbit = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
        $other = Project::query()->create(['name' => 'site', 'slug' => 'site', 'repository_url' => 'https://github.com/hardimpactdev/site.git', 'default_branch' => 'main', 'root' => 'public']);
        config(['proof.project' => $orbit->id]);
        $guest = verifyVm($other->id);

        $refusal = verifyRefused(fn () => app(ProofVerifyIntent::class)->start($guest, 'firewall:allow', null, 'tvm-700', null));

        expect($refusal->errorCode)->toBe('proof.wrong_project')->and(Operation::query()->count())->toBe(0);
    });

    it('records the intent of the caller\'s own VM: its workspace, its pinned SSH target, a restore first and a restore last, and no new VM', function (): void {
        $orbit = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
        config(['proof.project' => $orbit->id]);
        $guest = verifyVm($orbit->id);

        verifyStart($guest);

        $operation = Operation::query()->where('kind', 'proof.verify')->sole();
        $group = Task::topLevel()->sole();
        expect($operation->target_type)->toBe('task_group')->and($operation->target_id)->toBe($group->id)->and($operation->node_id)->toBe($guest->id)
            ->and($operation->input)->toMatchArray(['group_id' => $group->id, 'command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml',
                'checkout_path' => '/home/orbit/apps/orbit/workspace', 'restore_first' => true, 'final_restore' => true])
            ->and($operation->input['ssh_execution']['target'])->toMatchArray(['node_id' => $guest->id, 'host' => '10.44.0.150', 'user' => 'orbit', 'host_keys' => ['ssh-ed25519 fixture-public-key']])
            ->and(TaskVm::query()->count())->toBe(1)->and(Task::topLevel()->count())->toBe(1);
    });

    it('is not held by the turn of the caller, but is by any other operation on the group', function (): void {
        $orbit = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
        config(['proof.project' => $orbit->id]);
        $guest = verifyVm($orbit->id);
        $group = Task::topLevel()->sole();
        $held = fn (string $kind): Operation => Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $guest->id,
            'status' => OperationStatus::Running, 'input' => []]);

        $held('tasks.check');
        $refusal = verifyRefused(fn () => app(ProofVerifyIntent::class)->start($guest, 'firewall:allow', null, 'tvm-700', null));
        expect($refusal->errorCode)->toBe('operation.target_busy')->and(Operation::query()->where('kind', 'proof.verify')->count())->toBe(0);

        Operation::query()->where('kind', 'tasks.check')->delete();
        $held('tasks.turn');
        verifyStart($guest);
        expect(Operation::query()->where('kind', 'proof.verify')->count())->toBe(1);
    });

    it('refuses a group that has ended', function (): void {
        $orbit = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
        config(['proof.project' => $orbit->id]);
        $guest = verifyVm($orbit->id);
        Task::topLevel()->sole()->forceFill(['status' => TaskGroupStatus::Cancelled])->save();

        expect(verifyRefused(fn () => app(ProofVerifyIntent::class)->start($guest, 'firewall:allow', null, 'tvm-700', null))->errorCode)->toBe('tasks.group_closed');
    });

    it('refuses a group whose workspace is gone', function (): void {
        $orbit = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
        config(['proof.project' => $orbit->id]);
        $guest = verifyVm($orbit->id, '');

        expect(verifyRefused(fn () => app(ProofVerifyIntent::class)->start($guest, 'firewall:allow', null, 'tvm-700', null))->errorCode)->toBe('tasks.no_workspace');
    });

    it('names the cases file of a command', function (): void {
        expect(ProofVerifyIntent::casesFile('firewall:allow'))->toBe('swamp/proof/firewall/allow.cases.yaml')
            ->and(ProofVerifyIntent::casesFile('task:pr-ready'))->toBe('swamp/proof/task/pr-ready.cases.yaml');
    });
});
