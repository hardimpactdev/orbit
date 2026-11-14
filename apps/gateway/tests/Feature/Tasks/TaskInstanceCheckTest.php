<?php

declare(strict_types=1);

use App\Data\Tasks\StartTaskCheckData;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckIntent;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckProjection;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    Queue::fake([StartAdmittedSwampRun::class]);
});

/**
 * The Swamp regression's captured tasks.check workflow intent for a container Instance workspace.
 *
 * @return array<string, mixed>
 */
function instanceCheckIntent(): array
{
    $fixture = ValidatedData::object(json_decode((string) file_get_contents(base_path('../../swamp/tests/fixtures/task-instance-workspace.json')), true, flags: JSON_THROW_ON_ERROR));

    return ValidatedData::object(ValidatedData::object($fixture['check'])['intent']);
}

/**
 * A group that owns a running container Instance on its Node, with no task VM.
 *
 * @return array{Task, Task, Instance, InstanceRuntime}
 */
function instanceCheckWorld(): array
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'work', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.71', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.71', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $node->forceFill(['instance_bridge' => '10.253.0.0/24'])->saveQuietly();
    $project = Project::query()->create(['name' => 'Proof', 'slug' => 'proof', 'repository_url' => 'https://github.com/example/proof.git', 'default_branch' => 'main', 'root' => 'public',
        'task_check' => instanceCheckIntent()['command']]);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running,
        'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'proof-instance', 'checkout_path' => '/srv/proof-instance',
        'status' => InstanceState::Active, 'branch' => 'proof-branch', 'task_workspace_owner_id' => $group->id]);
    $runtime = InstanceRuntime::query()->create(['node_id' => $node->id, 'power_state' => 'running', 'runtime_version' => 'test', 'agent_version' => 'test', 'instance_id' => $instance->id,
        'incus_project' => 'alternate-proof', 'container_name' => 'proof-instance', 'bridge_address' => '10.253.0.5', 'seed_commit' => str_repeat('c', 40)]);
    $group->update(['taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id]);
    $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Change the app', 'brief' => 'Change it.', 'status' => 'todo',
        'deliverables' => instanceCheckIntent()['deliverables']]);

    return [$group->refresh(), $subtask, $instance, $runtime];
}

it('admits a check in the recorded container Instance without a task VM', function (): void {
    [$group, $subtask, $instance, $runtime] = instanceCheckWorld();

    $operation = app(TaskCheckIntent::class)->start($group, new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null);

    $intent = TaskCheckProjection::workflowInput($operation->input)['intent'];
    $workspace = ValidatedData::object($intent['workspace']);
    expect($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->kind)->toBe('tasks.check')
        ->and($intent['node_id'])->toBe($runtime->node_id)
        ->and($intent['checkout_path'])->toBe('/srv/workspace')
        ->and($intent['check_name'])->toBeNull()
        ->and($workspace['runtime'])->toBe(['project' => 'alternate-proof', 'name' => 'proof-instance'])
        ->and($workspace['branch'])->toBe('proof-branch')
        ->and($workspace['snapshot_id'])->toMatch('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/')
        ->and(data_get($intent, 'ssh_execution.target.host'))->toBe('10.44.0.71')
        ->and($operation->input['execution_secrets'])->toBe([])
        // The Swamp regression runs exactly this intent shape through the production phases.
        ->and(array_keys($intent))->toEqualCanonicalizing(array_keys(instanceCheckIntent()))
        ->and(array_keys($workspace))->toEqualCanonicalizing(array_keys(ValidatedData::object(instanceCheckIntent()['workspace'])))
        ->and(TaskVm::query()->count())->toBe(0)
        ->and(Operation::query()->where('kind', 'task_vm.resume')->count())->toBe(0)
        ->and(TaskCheck::query()->sole()->status->value)->toBe('running');
});

it('refuses container checks that need hosted CI or a stopped runtime before recording a check', function (Closure $arrange, TaskCheckKind $phase, string $code): void {
    [$group, $subtask, $instance, $runtime] = instanceCheckWorld();
    $arrange($group, $runtime);

    expect(fn () => app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, $phase), 'test', null))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe($code));
    expect(TaskCheck::query()->count())->toBe(0)
        ->and(Operation::query()->count())->toBe(0);
})->with([
    'hosted handoff CI' => [fn (Task $group) => $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required', 'source_access' => ProjectSourceAccess::GitHubApp]), TaskCheckKind::Handoff, 'tasks.instance_hosted_check_unavailable'],
    'stopped runtime' => [fn (Task $group, InstanceRuntime $runtime) => $runtime->update(['power_state' => 'stopped']), TaskCheckKind::Handoff, 'tasks.instance_not_running'],
    'no recorded branch' => [fn (Task $group) => Instance::query()->whereKey($group->taskable_id)->update(['branch' => null]), TaskCheckKind::Handoff, 'tasks.no_workspace'],
]);

/** Two setup steps on the Project, the last one Gateway-owned, as on the Orbit Project. */
function instanceCheckSetupSteps(Task $group): void
{
    foreach ([['install', 'composer install --no-interaction', 45], ['caches', '@orbit/ci-caches', 60]] as $position => [$name, $command, $seconds]) {
        ProjectLifecycleStep::query()->create(['project_id' => $group->project_id, 'phase' => LifecyclePhase::Setup->value, 'position' => $position + 1,
            'name' => $name, 'command' => $command, 'timeout_seconds' => $seconds]);
    }
}

it('runs a container baseline without the setup steps, because the copied seed already ran them', function (): void {
    [$group, $subtask] = instanceCheckWorld();
    instanceCheckSetupSteps($group);

    $operation = app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Baseline), 'test', null);

    $intent = TaskCheckProjection::workflowInput($operation->input)['intent'];
    expect($operation->kind)->toBe('tasks.check')
        ->and($intent['phase'])->toBe('baseline')
        ->and($intent['setup'])->toBe([])
        ->and($intent['command'])->toBe($group->project->task_check)
        ->and($intent['deliverables'])->toBe([])
        ->and(array_keys($intent))->toEqualCanonicalizing(array_keys(instanceCheckIntent()))
        ->and(TaskCheck::query()->sole()->kind)->toBe(TaskCheckKind::Baseline);
});

it('keeps every setup step in a task VM baseline', function (): void {
    [$group, $subtask, $instance] = instanceCheckWorld();
    instanceCheckSetupSteps($group);
    $guest = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'tvm-599', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.138', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.138', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    TaskVm::query()->create(['host_node_id' => $guest->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-599',
        'state' => TaskVmState::Ready, 'address' => '10.251.78.34', 'wireguard_ip' => '10.44.0.138', 'size' => TaskVmSize::Normal, 'pi_token' => str_repeat('q', 40)]);
    $instance->update(['task_workspace_owner_id' => null]);
    $workspace = Instance::query()->create(['project_id' => $group->project_id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/proof/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Active]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);

    $operation = app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Baseline), 'test', null);

    expect($operation->input['setup'])->toBe([
        ['name' => 'install', 'command' => 'composer install --no-interaction', 'timeout_seconds' => 45],
        ['name' => 'caches', 'command' => '@orbit/ci-caches', 'timeout_seconds' => 60],
    ])->and($operation->input)->not->toHaveKey('workspace');
});

it('keeps the task VM check intent unchanged', function (): void {
    [$group, $subtask, $instance] = instanceCheckWorld();
    $guest = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'tvm-598', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.137', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.137', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    TaskVm::query()->create(['host_node_id' => $guest->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-598',
        'state' => TaskVmState::Ready, 'address' => '10.251.78.33', 'wireguard_ip' => '10.44.0.137', 'size' => TaskVmSize::Normal, 'pi_token' => str_repeat('p', 40)]);
    $instance->update(['task_workspace_owner_id' => null]);
    $workspace = Instance::query()->create(['project_id' => $group->project_id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/proof/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Active]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);

    $operation = app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null);

    expect(array_keys($operation->input))->toBe(['subtask', 'phase', 'group_id', 'instance_id', 'node_id', 'checkout_path', 'ssr_port', 'check_id', 'command', 'setup',
        'deliverables', 'base', 'message', 'repository', 'check_name', 'branch', 'ssh_execution', 'execution_secrets', 'admitted_at', 'run_budget_seconds'])
        ->and($operation->input['node_id'])->toBe($guest->id)
        ->and($operation->input['checkout_path'])->toBe('/home/orbit/apps/proof/workspace')
        ->and(data_get($operation->input, 'ssh_execution.target.host'))->toBe('10.44.0.137');
});

it('projects the receipt of the Instance check as it projects a task VM check', function (): void {
    [$group, $subtask] = instanceCheckWorld();
    $operation = app(TaskCheckIntent::class)->start($group, new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null);
    $receipt = ['outcome' => 'passed', 'exit_code' => 0, 'output' => 'checked new', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40),
        'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40), 'changed_paths' => [], 'failed_step' => null, 'evidence' => null,
        'subtask_start_commit' => str_repeat('a', 40), 'check_id' => $operation->input['check_id'], 'group_id' => $group->id, 'subtask' => $subtask->id,
        'phase' => 'handoff', 'deliverables' => [], 'commit' => str_repeat('d', 40), 'ci' => null];

    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a']]);
    // The terminal reader fills `instance_receipt` from the `finish` output of the parent's Instance step.
    expect(data_get(app(DirectOperation::class)->definition('tasks.check'), 'terminal_result.captures.instance_receipt'))
        ->toBe(['job' => 'check', 'step' => 'Check in the Instance', 'field' => 'finish']);

    app(TaskCheckProjection::class)->apply($operation->refresh(), ['run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'succeeded',
        'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_receipt' => ['result_json' => json_encode($receipt, JSON_THROW_ON_ERROR)]]]);

    $check = TaskCheck::query()->sole();
    expect($check->status->value)->toBe('passed')
        ->and($check->commit_sha)->toBe(str_repeat('d', 40))
        ->and($check->output)->toBe('checked new');
});
