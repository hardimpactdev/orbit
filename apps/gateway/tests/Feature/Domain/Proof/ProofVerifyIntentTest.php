<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NestedFleetIntent;
use App\Domain\Instances\NestedFleetLayout;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofVerifyIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskInstanceWorkspace;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Support\ValidatedData;
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

/**
 * A task copy of a nested-fleet Project: the group owns a running clone (a T3 port) in the nested project on Beast, a Node whose
 * nested-fleet switch is on, with no task VM. Returns an operator Node with Gateway access, which names the group.
 *
 * @return array{Node, Task, Instance, InstanceRuntime}
 */
function verifyCopy(?string $container = null, string $incusProject = InstancePlacement::NestedProject, ?int $t3Port = 3801): array
{
    $beast = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.9', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $beast->forceFill(['instance_bridge' => '10.253.0.0/24', 'settings' => ['nested_fleet' => true]])->saveQuietly();
    $project = Project::query()->create(['name' => 'orbit-trial', 'slug' => 'orbit-trial', 'repository_url' => 'https://github.com/hardimpactdev/orbit-trial.git',
        'default_branch' => 'main', 'root' => 'public']);
    $project->forceFill(['nested_fleet' => true])->save();
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $beast->id, 'name' => 'task-1', 'checkout_path' => '/srv/task-1',
        'status' => InstanceState::Active, 'branch' => 'task-1', 'task_workspace_owner_id' => $group->id]);
    $runtime = InstanceRuntime::query()->create(['node_id' => $beast->id, 'power_state' => 'running', 'runtime_version' => 'test', 'agent_version' => 'test',
        'instance_id' => $instance->id, 'incus_project' => $incusProject, 'container_name' => $container ?? 'instance-'.$instance->id, 'bridge_address' => '10.253.0.5',
        'seed_commit' => str_repeat('c', 40), 't3_port' => $t3Port, 'container_server' => $t3Port === null ? null : 't3',
        'idmap_size' => $incusProject === InstancePlacement::NestedProject ? InstanceHostIntent::TaskPlaceIds : null]);
    $group->taskable()->associate($instance)->save();
    $mini = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'mini', 'status' => 'active', 'platform' => 'macos', 'architecture' => 'arm64',
        'public_ssh_host' => '10.44.0.12', 'wireguard_ip' => '10.44.0.12', 'user' => 'nckrtl']));

    return [$mini, $group->refresh(), $instance, $runtime];
}

/** Admits the proof of a named group; the run start after the commit needs a Swamp server the suite has not. */
function verifyGroup(Node $caller, Task $group): void
{
    try {
        app(ProofVerifyIntent::class)->start($caller, 'firewall:allow', $group->id, 'mini', null);
    } catch (ResourceOperationException $error) {
        if (! str_starts_with($error->errorCode, 'swamp.')) {
            throw $error;
        }
    }
}

describe('orbit proof admission', function (): void {
    // Task VMs are removed: no calling Node stands for a group, so the group is named or the request is refused before it reads anything.
    it('refuses a request without a group, and records nothing', function (): void {
        [$mini] = verifyCopy();

        $refusal = verifyRefused(fn () => app(ProofVerifyIntent::class)->start($mini, 'firewall:allow', null, 'mini', null));

        expect($refusal->errorCode)->toBe('proof.group_required')->and($refusal->status)->toBe(422)->and(Operation::query()->where('kind', 'proof.verify')->count())->toBe(0);
    });

    it('refuses a named group that does not exist, and records nothing', function (): void {
        $mini = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'mini', 'status' => 'active', 'platform' => 'macos', 'architecture' => 'arm64',
            'public_ssh_host' => '10.44.0.12', 'wireguard_ip' => '10.44.0.12', 'user' => 'nckrtl']));

        $refusal = verifyRefused(fn () => app(ProofVerifyIntent::class)->start($mini, 'firewall:allow', 999, 'mini', null));

        expect($refusal->errorCode)->toBe('tasks.group_missing')->and($refusal->status)->toBe(404)->and(Operation::query()->count())->toBe(0);
    });

    it('is held by any open operation on the group, a copy turn too, but not by an old row of the removed Pi turn', function (): void {
        [$mini, $group, $instance] = verifyCopy();
        $held = fn (string $kind): Operation => Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $instance->node_id,
            'status' => OperationStatus::Running, 'input' => []]);

        $held('tasks.check');
        $refusal = verifyRefused(fn () => app(ProofVerifyIntent::class)->start($mini, 'firewall:allow', $group->id, 'mini', null));
        expect($refusal->errorCode)->toBe('operation.target_busy')->and(Operation::query()->where('kind', 'proof.verify')->count())->toBe(0);

        Operation::query()->where('kind', 'tasks.check')->delete();
        $held('tasks.copy_turn');
        $refusal = verifyRefused(fn () => app(ProofVerifyIntent::class)->start($mini, 'firewall:allow', $group->id, 'mini', null));
        expect($refusal->errorCode)->toBe('operation.target_busy');

        Operation::query()->where('kind', 'tasks.copy_turn')->delete();
        $held('tasks.turn');
        verifyGroup($mini, $group);
        expect(Operation::query()->where('kind', 'proof.verify')->count())->toBe(1);
    });

    it('refuses a group that has ended', function (): void {
        [$mini, $group] = verifyCopy();
        $group->forceFill(['status' => TaskGroupStatus::Cancelled])->save();

        expect(verifyRefused(fn () => app(ProofVerifyIntent::class)->start($mini, 'firewall:allow', $group->id, 'mini', null))->errorCode)->toBe('tasks.group_closed');
    });

    it('refuses a group that owns no task copy', function (): void {
        [$mini, $group] = verifyCopy();
        $group->forceFill(['taskable_id' => null, 'taskable_type' => null])->save();

        $refusal = verifyRefused(fn () => app(ProofVerifyIntent::class)->start($mini, 'firewall:allow', $group->id, 'mini', null));

        expect($refusal->errorCode)->toBe('tasks.no_workspace')->and($refusal->getMessage())->toBe('The group has no task copy.');
    });

    it('records the intent of a task copy: its container, its fleet, its Node as the SSH target, the guest checkout, a reset before every batch and no restore last', function (): void {
        config(['proof.project' => 46]);
        [$mini, $group, $instance, $runtime] = verifyCopy();

        verifyGroup($mini, $group);

        $operation = Operation::query()->where('kind', 'proof.verify')->sole();
        $fixture = ValidatedData::object(ValidatedData::object(json_decode((string) file_get_contents(base_path('../../swamp/tests/fixtures/proof-verify-copy.json')), true,
            flags: JSON_THROW_ON_ERROR))['intent']);
        expect($operation->target_id)->toBe($group->id)->and($operation->node_id)->toBe($instance->node_id)
            ->and(array_keys($operation->input))->toEqualCanonicalizing(array_keys($fixture))
            ->and($operation->input)->toMatchArray(['group_id' => $group->id, 'instance_id' => $instance->id, 'command' => 'firewall:allow',
                'file' => 'swamp/proof/firewall/allow.cases.yaml', 'checkout_path' => TaskInstanceWorkspace::Root, 'restore_first' => true, 'final_restore' => false,
                'copy' => ['project' => InstancePlacement::NestedProject, 'name' => $runtime->container_name]])
            // The play of the reset reads the container and the fleet as every fleet phase does (swamp/ansible/tasks/instance-fleet.yml).
            ->and(ValidatedData::object($operation->input['container']))->toMatchArray(['project' => InstancePlacement::NestedProject, 'name' => $runtime->container_name])
            ->and($operation->input['fleet'])->toBe(app(NestedFleetIntent::class)->forClone($instance, $runtime))
            ->and(ValidatedData::object($operation->input['fleet'])['guests'])->toBe(NestedFleetLayout::Guests)
            ->and(ValidatedData::object(ValidatedData::object($operation->input['ssh_execution'])['target']))->toMatchArray(['node_id' => $instance->node_id, 'host' => '10.44.0.9', 'user' => 'orbit']);
    });

    it('refuses a task copy that cannot hold a proof, and records nothing', function (Closure $arrange, string $code): void {
        [$mini, $group, $instance, $runtime] = verifyCopy();
        $arrange($group, $instance, $runtime);
        $before = Operation::query()->count();

        $refusal = verifyRefused(fn () => app(ProofVerifyIntent::class)->start($mini, 'firewall:allow', $group->id, 'mini', null));

        expect($refusal->errorCode)->toBe($code)->and(Operation::query()->count())->toBe($before);
    })->with([
        'a Project without a nested fleet' => [fn (Task $group) => $group->project->forceFill(['nested_fleet' => false])->save(), 'proof.wrong_project'],
        'a copy that is not active yet' => [fn (Task $group, Instance $instance) => $instance->forceFill(['status' => InstanceState::SourceResolved])->save(), 'tasks.copy_not_ready'],
        'a copy whose completion failed' => [function (Task $group, Instance $instance): void {
            $instance->forceFill(['status' => InstanceState::SourceResolved])->save();
            Operation::query()->create(['kind' => 'instance.complete', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
                'status' => OperationStatus::Failed, 'error_code' => 'instance.container_fleet_drift', 'input' => []]);
        }, 'tasks.copy_completion_failed'],
        'a stopped container' => [fn (Task $group, Instance $instance, InstanceRuntime $runtime) => $runtime->update(['power_state' => 'stopped']), 'tasks.instance_not_running'],
        'a copy turn that may still run' => [fn (Task $group) => Operation::query()->create(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group', 'target_id' => $group->id,
            'status' => OperationStatus::Failed, 'input' => ['thread_id' => 1], 'state' => ['swamp_run_id' => 'run-1'], 'result' => []]), 'tasks.copy_turn_unended'],
        // The reset runs only on a clone that keeps its fleet; see also the refusal of a default below.
        'a Node with the nested-fleet switch off' => [fn (Task $group, Instance $instance) => $instance->node->forceFill(['settings' => ['nested_fleet' => false]])->saveQuietly(),
            'proof.copy_fleet_missing'],
        'a copy turn that runs' => [fn (Task $group, Instance $instance) => Operation::query()->create(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group',
            'target_id' => $group->id, 'node_id' => $instance->node_id, 'status' => OperationStatus::Running, 'input' => []]), 'operation.target_busy'],
    ]);

    it('refuses an Instance that is not a clone that keeps its fleet, such as a default: the reset never runs on it, and nothing is recorded', function (string $incusProject, ?int $t3Port): void {
        [$mini, $group] = verifyCopy(null, $incusProject, $t3Port);

        $refusal = verifyRefused(fn () => app(ProofVerifyIntent::class)->start($mini, 'firewall:allow', $group->id, 'mini', null));

        expect($refusal->errorCode)->toBe('proof.copy_fleet_missing')->and($refusal->status)->toBe(409)
            ->and(Operation::query()->where('kind', 'proof.verify')->count())->toBe(0);
    })->with([
        // Instance 2454's shape: a Project's default in the nested project holds the fleet that every copy starts from, and has no T3 port.
        'a default in the nested project' => [InstancePlacement::NestedProject, null],
        'a clone outside the nested project' => ['orbit-instances', 3801],
    ]);

    it('admits a second proof, and a proof after an agent turn ended: each batch resets the fleet first', function (): void {
        [$mini, $group, $instance] = verifyCopy();
        Operation::query()->create(['kind' => 'proof.verify', 'target_type' => 'task_group', 'target_id' => $group->id, 'status' => OperationStatus::Succeeded, 'input' => []]);
        Operation::query()->create(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'status' => OperationStatus::Succeeded,
            'input' => ['thread_id' => 1], 'result' => ['ended' => true]]);

        verifyGroup($mini, $group);

        expect(Operation::query()->where('kind', 'proof.verify')->count())->toBe(2)
            ->and(Operation::query()->where('kind', 'proof.verify')->latest('id')->firstOrFail()->input['restore_first'])->toBeTrue();
    });

    it('refuses a recorded Incus project or container name that reads as an option of incus exec', function (?string $container, string $project): void {
        [$mini, $group, $instance, $runtime] = verifyCopy($container, $project);

        $refusal = verifyRefused(fn () => app(ProofVerifyIntent::class)->start($mini, 'firewall:allow', $group->id, 'mini', null));

        expect($refusal->errorCode)->toBe('tasks.no_workspace')->and(Operation::query()->where('kind', 'proof.verify')->count())->toBe(0);
    })->with([
        'a container that is an option' => ['--force', 'orbit-instances'],
        'a container that adds an argument' => ['instance-1 --project=default', 'orbit-instances'],
        'a container that ends the options' => ['--', 'orbit-instances'],
        'a container on another remote' => ['beast:instance-1', 'orbit-instances'],
        'a project that is an option' => [null, '-p'],
        'a project that adds an argument' => [null, 'orbit-instances exec instance-1'],
    ]);

    it('names the cases file of a command', function (): void {
        expect(ProofVerifyIntent::casesFile('firewall:allow'))->toBe('swamp/proof/firewall/allow.cases.yaml')
            ->and(ProofVerifyIntent::casesFile('task:pr-ready'))->toBe('swamp/proof/task/pr-ready.cases.yaml');
    });
});
