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
use App\Domain\Tasks\TaskPrMode;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
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
        'status' => InstanceState::Active, 'branch' => 'proof-branch', 'task_workspace_owner_id' => $group->id, 'starting_commit' => str_repeat('c', 40)]);
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

    $intent = TaskCheckProjection::inputOf($operation->input)['intent'];
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
        // The Swamp regression runs exactly this intent shape through the production phases; a copy's handoff adds its base.
        ->and(array_keys(array_diff_key($intent, ['copy_base' => true])))->toEqualCanonicalizing(array_keys(instanceCheckIntent()))
        ->and(array_diff_key(ValidatedData::object($intent['copy_base']), ['git_authorization' => true]))->toBe([
            'repository' => 'https://github.com/example/proof.git', 'default_branch' => 'main', 'start' => str_repeat('c', 40),
            'mirror' => '/var/lib/orbit/instance-repositories/'.$instance->id.'.git', 'source_mirror' => ''])
        ->and(data_get($intent, 'copy_base.git_authorization.vault'))->toBe('orbit-control-inputs')
        ->and(data_get($intent, 'copy_base.git_authorization.key'))->toMatch('/^task-sync-token-[a-f0-9-]{36}$/')
        ->and(array_keys($workspace))->toEqualCanonicalizing(array_keys(ValidatedData::object(instanceCheckIntent()['workspace'])))
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

    $intent = TaskCheckProjection::inputOf($operation->input)['intent'];
    expect($operation->kind)->toBe('tasks.check')
        ->and($intent['phase'])->toBe('baseline')
        ->and($intent['setup'])->toBe([])
        ->and($intent['command'])->toBe($group->project->task_check)
        ->and($intent['deliverables'])->toBe([])
        ->and(array_keys($intent))->toEqualCanonicalizing(array_keys(instanceCheckIntent()))
        // A baseline brings in no base, so it names no read token.
        ->and($intent)->not->toHaveKey('copy_base')
        ->and(TaskCheck::query()->sole()->kind)->toBe(TaskCheckKind::Baseline);
});

it('mints the read token of a copy handoff check as the run starts, and none for a baseline', function (): void {
    [$group, $subtask] = instanceCheckWorld();
    $vault = new class implements WebSocketClient
    {
        /** @var list<array{key: string, value: string}> */
        public array $puts = [];

        /** @var list<array<string, mixed>> */
        private array $frames = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);
            $this->puts[] = ['key' => $frame['payload']['key'], 'value' => (string) $frame['payload']['value']];

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $vault);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    $baseline = app(TaskCheckIntent::class)->start($group, new StartTaskCheckData($subtask->id, TaskCheckKind::Baseline), 'test', null);
    $inputs = app(DirectOperation::class)->workflowInputs($baseline);
    expect($vault->puts)->toBe([])
        ->and($inputs['intent'])->not->toHaveKey('copy_base');
    $baseline->update(['status' => OperationStatus::Succeeded, 'finished_at' => now()]);
    TaskCheck::query()->update(['status' => 'passed']);

    $handoff = app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', 'second');
    $key = ValidatedData::string(data_get($handoff->input, 'copy_base.git_authorization.key'));
    $inputs = app(DirectOperation::class)->workflowInputs($handoff);

    // A Project without GitHub App source access fetches anonymously: the header is empty, but the run gets its own key.
    $patterns = array_column(ValidatedData::object(app(DirectOperation::class)->definition('tasks.check'))['execution_copies'], 'key_pattern');
    expect($vault->puts)->toBe([['key' => $key, 'value' => '']])
        ->and(data_get($inputs, 'intent.copy_base.git_authorization'))->toBe(['vault' => 'orbit-control-inputs', 'key' => $key])
        ->and($inputs['execution_secrets'])->toEqual((object) [])
        ->and(array_filter($patterns, fn (string $pattern): bool => preg_match('/'.$pattern.'/', $key) === 1))->toHaveCount(1);
});

// The task VM check is removed with the task VMs (docs/reference/old-paths.md): a group whose workspace sat on a task VM has no task copy.
it('refuses a check of a group whose workspace is on a task VM by name, and records no check row', function (TaskCheckKind $phase): void {
    [$group, $subtask, $instance] = instanceCheckWorld();
    $guest = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'tvm-598', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.137', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.137', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $instance->update(['task_workspace_owner_id' => null]);
    $workspace = Instance::query()->create(['project_id' => $group->project_id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/proof/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Active]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);

    expect(fn () => app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, $phase), 'test', null))
        ->toThrow(fn (ResourceOperationException $refusal) => expect([$refusal->errorCode, $refusal->getMessage()])->toBe(['tasks.no_workspace', 'The group requires its task copy.']))
        ->and($subtask->checks()->count())->toBe(0)
        ->and(Operation::query()->where('kind', 'tasks.check')->exists())->toBeFalse();
})->with([TaskCheckKind::Baseline, TaskCheckKind::Handoff]);

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

it('keeps the base of a copy handoff check, and a conflict fails with its paths', function (): void {
    [$group, $subtask] = instanceCheckWorld();
    $main = str_repeat('e', 40);
    $operation = app(TaskCheckIntent::class)->start($group, new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null);
    $receipt = ['outcome' => 'passed', 'exit_code' => 0, 'output' => '', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40),
        'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40), 'changed_paths' => [], 'failed_step' => null, 'evidence' => null,
        'check_id' => $operation->input['check_id'], 'group_id' => $group->id, 'subtask' => $subtask->id, 'phase' => 'handoff', 'deliverables' => [],
        'commit' => str_repeat('d', 40), 'ci' => null, 'base' => 'current', 'default_commit' => $main, 'merged_default' => $main];
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a']]);

    app(TaskCheckProjection::class)->apply($operation->refresh(), ['run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'succeeded',
        'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_receipt' => ['result_json' => json_encode($receipt, JSON_THROW_ON_ERROR)]]]);

    // The driver and the reviewer read the base the check judged against from the Operation's result.
    expect(array_intersect_key($operation->refresh()->result ?? [], array_flip(['base', 'default_commit', 'merged_default'])))
        ->toBe(['base' => 'current', 'default_commit' => $main, 'merged_default' => $main])
        ->and(TaskCheck::query()->sole()->status->value)->toBe('passed');

    TaskCheck::query()->delete();
    $conflict = app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', 'conflict');
    $refusal = ['error_code' => 'tasks.check_conflict', 'check_id' => $conflict->input['check_id'], 'base' => 'current', 'default_commit' => $main,
        'merged_default' => null, 'conflicting_paths' => ['apps/gateway/app/Domain/Tasks/TaskCheckIntent.php'], 'conflicting_more' => 0];
    $conflict->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '7b69badf-4f06-4f2e-82d7-21b62c1f587a']]);

    app(TaskCheckProjection::class)->apply($conflict->refresh(), ['run_id' => '7b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed',
        'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_receipt' => ['result_json' => json_encode($refusal, JSON_THROW_ON_ERROR)]]]);

    expect($conflict->refresh()->error_code)->toBe('tasks.check_conflict')
        ->and($conflict->result['conflicting_paths'] ?? null)->toBe(['apps/gateway/app/Domain/Tasks/TaskCheckIntent.php'])
        ->and(TaskCheck::query()->sole()->status->value)->toBe('failed');
});

/** Runs a handoff check of the copy's subtask to its terminal result with this outcome. */
function instanceCheckFinished(Task $group, Task $subtask, string $outcome, string $status = 'succeeded'): TaskCheck
{
    $operation = app(TaskCheckIntent::class)->start($group, new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null);
    $receipt = ['outcome' => $outcome, 'exit_code' => $outcome === 'passed' ? 0 : 1, 'output' => '', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40),
        'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40), 'changed_paths' => [], 'failed_step' => null, 'evidence' => null,
        'check_id' => $operation->input['check_id'], 'group_id' => $group->id, 'subtask' => $subtask->id, 'phase' => 'handoff', 'deliverables' => [], 'commit' => str_repeat('d', 40), 'ci' => null];
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a']]);
    app(TaskCheckProjection::class)->apply($operation->refresh(), ['run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => $status,
        'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_receipt' => ['result_json' => json_encode($receipt, JSON_THROW_ON_ERROR)]]]);

    return TaskCheck::query()->sole();
}

it('records the mode in effect on a passed handoff check of a task copy', function (?TaskPrMode $group, string $gateway, TaskPrMode $recorded): void {
    config(['orbit.tasks.pr_mode' => $gateway]);
    [$owner, $subtask] = instanceCheckWorld();
    $owner->update(['pr_mode' => $group]);

    expect(instanceCheckFinished($owner->refresh(), $subtask, 'passed')->pr_mode)->toBe($recorded);
})->with([
    'the Gateway default' => [null, 'review_before_pr', TaskPrMode::ReviewBeforePr],
    'the Gateway in pr_when_done' => [null, 'pr_when_done', TaskPrMode::PrWhenDone],
    'the group' => [TaskPrMode::PrWhenDone, 'review_before_pr', TaskPrMode::PrWhenDone],
]);

it('records no mode on a check that did not pass, and none when the Gateway setting is no mode', function (string $outcome, string $gateway): void {
    config(['orbit.tasks.pr_mode' => $gateway]);
    [$owner, $subtask] = instanceCheckWorld();

    $check = instanceCheckFinished($owner, $subtask, $outcome);

    expect($check->status->value)->toBe($outcome)->and($check->pr_mode)->toBeNull();
})->with([
    'failed' => ['failed', 'pr_when_done'],
    'invalid setting' => ['passed', 'merge_when_green'],
]);

it('asks only a gated group\'s handoff check to keep the handed-off work as it was (docs/reference/tasks.md#the-delivery-gate)', function (): void {
    [$group, $subtask] = instanceCheckWorld();
    $ungated = app(TaskCheckIntent::class)->start($group, new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', 'ungated');
    $ungated->update(['status' => OperationStatus::Cancelled, 'finished_at' => now(), 'lock_key' => null]);
    $group->update(['handoffs_gated' => true]);
    $gated = app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', 'gated');

    expect($ungated->input['handoff_gate'])->toBeFalse()
        ->and($gated->input['handoff_gate'])->toBeTrue();
});
