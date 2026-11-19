<?php

declare(strict_types=1);

use App\Data\Tasks\StartTaskPublishData;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPublishIntent;
use App\Domain\Tasks\TaskPublishProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\NativeStepFailure;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;
use Tests\Support\WorkflowStartIntents;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    Queue::fake([StartAdmittedSwampRun::class]);
});

/**
 * A task copy with a passed handoff check of its subtask: a running container Instance that the group owns, no task VM.
 *
 * @return array{Task, TaskCheck, Instance, InstanceRuntime}
 */
function copyPublishWorld(?string $root = 'public', string $incusProject = 'orbit-instances'): array
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'work', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.71', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.71', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $node->forceFill(['instance_bridge' => '10.253.0.0/24'])->saveQuietly();
    $project = Project::query()->create(['name' => 'Trial', 'slug' => 'trial', 'repository_url' => 'https://github.com/example/trial.git', 'default_branch' => 'main', 'root' => $root]);
    config(['orbit.tasks.copy_publish_projects' => [$project->id]]);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/var/lib/incus/rootfs/srv/workspace',
        'status' => InstanceState::Active, 'branch' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'starting_commit' => str_repeat('a', 40)]);
    $runtime = InstanceRuntime::query()->create(['node_id' => $node->id, 'power_state' => 'running', 'runtime_version' => 'test', 'agent_version' => 'test', 'instance_id' => $instance->id,
        'incus_project' => $incusProject, 'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.5', 'seed_commit' => str_repeat('a', 40)]);
    $group->update(['taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id]);
    $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Change', 'brief' => 'Change it.', 'status' => 'todo']);
    $check = TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'pid' => 1, 'process_started' => 'x',
        'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('2', 40), 'head_after' => str_repeat('b', 40), 'tree_after' => str_repeat('4', 40),
        'commit_sha' => str_repeat('b', 40), 'exit_code' => 0, 'output' => '', 'started_at' => now(), 'finished_at' => now()]);
    Operation::query()->create(['kind' => OperationKinds::TasksCheck, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'input' => ['subtask' => $subtask->id, 'phase' => 'handoff', 'check_id' => $check->id], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1,
        'result' => ['outcome' => 'passed', 'check_id' => $check->id, 'commit' => $check->commit_sha, 'head' => $check->head_after, 'tree' => $check->tree_after, 'ci' => null]]);

    return [$group->refresh(), $check, $instance, $runtime];
}

it('publishes a task copy through its container, with its start commit and the path of the .env Orbit wrote', function (?string $root, string $environment): void {
    [$group, $check, $instance, $runtime] = copyPublishWorld($root);

    $operation = app(TaskPublishIntent::class)->start($group, StartTaskPublishData::from(['check' => $check->id]), 'test', null);

    $intent = ValidatedData::object(app(TaskPublishProjection::class)->input($operation)['intent']);
    $workspace = ValidatedData::object($intent['workspace']);
    expect($operation->kind)->toBe('tasks.publish')
        ->and($operation->status)->toBe(OperationStatus::Queued)
        ->and($intent['checkout_path'])->toBe('/srv/workspace')
        ->and($intent['commit'])->toBe($check->commit_sha)
        ->and($intent['tree'])->toBe($check->tree_after)
        ->and($intent['branch'])->toBe('task-'.$group->id)
        ->and($intent['repository'])->toBe('example/trial')
        ->and($intent['node_id'])->toBe($runtime->node_id)
        ->and(array_keys($workspace))->toBe(['runtime', 'branch', 'snapshot_id', 'start', 'environment_path'])
        ->and($workspace['runtime'])->toBe(['project' => 'orbit-instances', 'name' => 'instance-'.$instance->id])
        ->and($workspace['branch'])->toBe('task-'.$group->id)
        ->and($workspace['start'])->toBe(str_repeat('a', 40))
        ->and($workspace['environment_path'])->toBe($environment)
        ->and(Operation::query()->where('kind', 'task_vm.resume')->count())->toBe(0);
})->with([
    'root .env' => ['public', '.env'],
    'application folder' => ['apps/web/public', 'apps/web/.env'],
]);

/** A native vault that records each put, as the Swamp serve socket answers it. */
function publishEnvironmentVault(): object
{
    return new class implements WebSocketClient
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
}

it('gives the run of a copy publish the .env Orbit renders for the Instance as a vault copy, and a task VM publish none', function (): void {
    [$group, $check, $instance] = copyPublishWorld();
    InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'env_key' => 'APP_KEY', 'env_value' => 'base64:not-a-real-key']);
    app()->instance(WebSocketClient::class, $vault = publishEnvironmentVault());
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);
    $operation = app(TaskPublishIntent::class)->start($group, StartTaskPublishData::from(['check' => $check->id]), 'test', null);

    $inputs = app(DirectOperation::class)->workflowInputs($operation);

    $key = 'task-publish-environment-'.$operation->id;
    $rendered = app(DevelopmentCreateEnvironment::class)->content($instance->refresh());
    expect($inputs['execution_secrets']['environment'])->toBe(['vault' => 'orbit-control-inputs', 'key' => $key])
        ->and($vault->puts)->toBe([['key' => $key, 'value' => $rendered]])
        ->and($rendered)->toContain('base64:not-a-real-key')
        ->and(json_encode($inputs))->not->toContain('not-a-real-key')
        ->and($operation->refresh()->input)->not->toHaveKey('execution_secrets.environment');

    $vm = $operation->replicate();
    $vm->input = array_diff_key($operation->input, ['workspace' => true]);
    expect(app(DirectOperation::class)->workflowInputs($vm)['execution_secrets'])->not->toHaveKey('environment')
        ->and($vault->puts)->toHaveCount(1);
});

it('gives the run of a nested-fleet copy the Club key reference the nested starts read, and no other copy', function (?string $key, bool $nested, bool $expected): void {
    [$group, $check] = copyPublishWorld('public', $nested ? InstancePlacement::NestedProject : 'orbit-instances');
    $base = sys_get_temp_dir().'/orbit-publish-club-'.bin2hex(random_bytes(4));
    mkdir($base.'/shared', 0700, true);
    file_put_contents($base.'/shared/gateway.env', "APP_NAME=Orbit\n".($key === null ? '' : 'ORBIT_NESTED_FLEET_CLUB_API_KEY='.$key."\n"));
    chmod($base.'/shared/gateway.env', 0600);
    app()->instance(GatewayReleaseLayout::class, new GatewayReleaseLayout($base.'/current/apps/gateway'));
    app()->instance(WebSocketClient::class, $vault = publishEnvironmentVault());
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);
    $operation = app(TaskPublishIntent::class)->start($group, StartTaskPublishData::from(['check' => $check->id]), 'test', null);

    $inputs = app(DirectOperation::class)->workflowInputs($operation);

    $workspace = $inputs['intent']['workspace'] ?? $inputs['workspace'];
    if ($expected) {
        expect($inputs['execution_secrets']['club_key'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'nested-fleet-club-api-key'])
            ->and($workspace['club_key'])->toBeTrue()
            ->and(collect($vault->puts)->firstWhere('key', 'nested-fleet-club-api-key'))->toBe(['key' => 'nested-fleet-club-api-key', 'value' => $key]);
    } else {
        expect($inputs['execution_secrets'])->not->toHaveKey('club_key')
            ->and($workspace)->not->toHaveKey('club_key')
            ->and(collect($vault->puts)->pluck('key')->all())->not->toContain('nested-fleet-club-api-key');
    }
    expect(json_encode($inputs))->not->toContain('fake-nested-club-key')
        ->and($operation->refresh()->input['workspace'])->not->toHaveKey('club_key');
})->with([
    'nested copy with a key' => ['fake-nested-club-key-for-publish', true, true],
    'nested copy, the Gateway holds no key' => [null, true, false],
    'normal clone' => ['fake-nested-club-key-for-publish', false, false],
]);

it('refuses a copy publish without a start commit or a running container, before any Operation', function (Closure $arrange, string $code): void {
    [$group, $check, $instance, $runtime] = copyPublishWorld();
    $arrange($instance, $runtime);

    expect(fn () => app(TaskPublishIntent::class)->start($group->refresh(), StartTaskPublishData::from(['check' => $check->id]), 'test', null))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe($code));
    expect(Operation::query()->where('kind', 'tasks.publish')->count())->toBe(0);
})->with([
    'no start commit' => [fn (Instance $instance) => $instance->update(['starting_commit' => null]), 'tasks.no_workspace'],
    'stopped container' => [fn (Instance $instance, InstanceRuntime $runtime) => $runtime->update(['power_state' => 'stopped']), 'tasks.instance_not_running'],
]);

it('holds a copy publish for a Project that is not released for it, before any Operation; a listed or nested-fleet Project publishes', function (): void {
    [$group, $check] = copyPublishWorld();
    $publish = fn (): Operation => app(TaskPublishIntent::class)->start($group->refresh(), StartTaskPublishData::from(['check' => $check->id]), 'test', null);

    foreach ([[], [$group->project_id + 1], (object) [], null, [(string) $group->project_id]] as $listed) {
        config(['orbit.tasks.copy_publish_projects' => $listed]);
        expect($publish)->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('tasks.copy_publish_held')
            ->and($exception->status)->toBe(409)->and($exception->getMessage())->toContain('The checked commit stays in the task copy'));
    }
    expect(Operation::query()->where('kind', 'tasks.publish')->count())->toBe(0);

    $group->project->forceFill(['nested_fleet' => true])->saveQuietly();
    expect($publish()->kind)->toBe('tasks.publish');
});

it('keeps the task VM path for a group without a container: no VM workspace refuses as before', function (): void {
    [$group, $check, $instance, $runtime] = copyPublishWorld();
    $runtime->delete();

    expect(fn () => app(TaskPublishIntent::class)->start($group->refresh(), StartTaskPublishData::from(['check' => $check->id]), 'test', null))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('tasks.no_workspace'));
});

it('names a refused environment file, its content and an unchecked content with their own codes', function (string $step, ?string $detail, string $code): void {
    [$group, $check] = copyPublishWorld();
    $operation = app(TaskPublishIntent::class)->start($group, StartTaskPublishData::from(['check' => $check->id]), 'test', null);

    $run = '6b69badf-4f06-4f2e-82d7-21b62c1f587a';
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run]]);

    $projected = app(TaskPublishProjection::class)->apply($operation->refresh(), ['run_id' => $run, 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => [], 'failure' => ['failed_step' => $step, 'detail' => $detail]]);

    expect($projected->error_code)->toBe($code)
        ->and($group->refresh()->pr_url)->toBeNull();
})->with([
    'path' => ['Check the environment file', null, 'tasks.publish_secret_path'],
    'content' => ['Check the .env content', "warning: x\ntasks.publish_secret_content: a value of the .env is in the file 'a.txt' in commit ".str_repeat('b', 40)."\n", 'tasks.publish_secret_content'],
    'check error' => ['Check the .env content', "fatal: bad revision\n", 'tasks.publish_secret_unchecked'],
    'no detail' => ['Check the .env content', null, 'tasks.publish_secret_unchecked'],
    'refusal line only mid-line' => ['Check the .env content', 'x tasks.publish_secret_content: y', 'tasks.publish_secret_unchecked'],
    'typed read of a refusal' => ['Read the .env check', "tasks.publish_secret_content: a value of the .env is in the file 'notes/a.txt' (".str_repeat('c', 40).') in commit '.str_repeat('d', 40), 'tasks.publish_secret_content'],
    'typed read of an error' => ['Read the .env check', 'tasks.publish_secret_unchecked', 'tasks.publish_secret_unchecked'],
]);

it('names the copy check\'s and the base check\'s refusal with its code and its paths, lines and secret names, and nothing is pushed', function (string $step, ?string $detail, array $result): void {
    [$group, $check] = copyPublishWorld();
    $operation = app(TaskPublishIntent::class)->start($group, StartTaskPublishData::from(['check' => $check->id]), 'test', null);
    $run = '6b69badf-4f06-4f2e-82d7-21b62c1f587a';
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run]]);

    $projected = app(TaskPublishProjection::class)->apply($operation->refresh(), ['run_id' => $run, 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => [], 'failure' => ['failed_step' => $step, 'detail' => $detail]]);

    expect($projected->error_code)->toBe($result['error_code'])->and($projected->result)->toBe($result)
        ->and($group->refresh()->pr_url)->toBeNull();
})->with([
    'a secret value' => ['Read the copy check', 'tasks.publish_secret_value: "notes/leak.txt:2 nested-gateway:env:/var/lib/orbit-checkout-state/apps/gateway/.env:APP_KEY", "commit '.str_repeat('a', 40).' club-key:/run/orbit-club/swamp-club-api-key" and 3 more',
        ['error_code' => 'tasks.publish_secret_value', 'refused' => ['notes/leak.txt:2 nested-gateway:env:/var/lib/orbit-checkout-state/apps/gateway/.env:APP_KEY', 'commit '.str_repeat('a', 40).' club-key:/run/orbit-club/swamp-club-api-key'], 'refused_more' => 3]],
    // The base check judges workflow changes against the real default head (docs/reference/tasks.md#the-base-of-a-copy-publish).
    'a workflow change' => ['Check the base', 'tasks.publish_workflow_change: ".github/workflows/x.yml"', ['error_code' => 'tasks.publish_workflow_change', 'refused' => ['.github/workflows/x.yml'], 'refused_more' => 0]],
    'many workflow changes' => ['Check the base', "Error: x\n".'tasks.publish_workflow_change: ".github/workflows/x.yml", ".github/workflows/y.yml" and 7 more', ['error_code' => 'tasks.publish_workflow_change', 'refused' => ['.github/workflows/x.yml', '.github/workflows/y.yml'], 'refused_more' => 7]],
    'a workflow line of the copy check' => ['Read the copy check', 'tasks.publish_workflow_change: ".github/workflows/x.yml"', ['error_code' => 'tasks.publish_secret_unchecked']],
    'an error of the check' => ['Read the copy check', 'tasks.publish_secret_unchecked', ['error_code' => 'tasks.publish_secret_unchecked']],
    'a failed check run' => ['Check the copy', 'tasks.workspace_phase_failed', ['error_code' => 'tasks.publish_secret_unchecked']],
    'no detail' => ['Read the copy check', null, ['error_code' => 'tasks.publish_secret_unchecked']],
]);

it('names a step that timed out on a Swamp behavior lock tasks.publish_lock_timeout, on any step, and nothing else', function (string $step, ?string $detail, string $code): void {
    [$group, $check] = copyPublishWorld();
    $operation = app(TaskPublishIntent::class)->start($group, StartTaskPublishData::from(['check' => $check->id]), 'test', null);
    $run = '6b69badf-4f06-4f2e-82d7-21b62c1f587a';
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run]]);

    $projected = app(TaskPublishProjection::class)->apply($operation->refresh(), ['run_id' => $run, 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => [], 'failure' => ['failed_step' => $step, 'detail' => $detail]]);

    expect($projected->error_code)->toBe($code)->and($projected->result)->toBe(['error_code' => $code]);
})->with([
    // The error of Operation 22074: its export waited 60 s for the workspace phase's behavior lock that another publish held.
    'the workspace phase' => ['Run workspace phase', 'Lock "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp/.swamp/data/command/shell/d52d75df-895d-4824-92c3-7bc1fea709d9/.lock" held by orbit@swamp (pid 3018532) — timed out after 60002ms', 'tasks.publish_lock_timeout'],
    'with a final newline' => ['Run workspace phase', 'Lock "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp/.swamp/data/command/shell/d52d75df-895d-4824-92c3-7bc1fea709d9/.lock" held by orbit@swamp (pid 3018532) — timed out after 60002ms'."\n", 'tasks.publish_lock_timeout'],
    'the push step' => ['Push the branch', 'Lock "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp/.swamp/data/command/shell/d52d75df-895d-4824-92c3-7bc1fea709d9/.lock" held by orbit@swamp (pid 3018532) — timed out after 60002ms', 'tasks.publish_lock_timeout'],
    'the content check' => ['Check the .env content', 'Lock "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp/.swamp/data/command/shell/d52d75df-895d-4824-92c3-7bc1fea709d9/.lock" held by orbit@swamp (pid 3018532) — timed out after 60002ms', 'tasks.publish_lock_timeout'],
    'a holder that let go at the deadline' => ['Run workspace phase', 'Lock "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp/.swamp/data/command/shell/d52d75df-895d-4824-92c3-7bc1fea709d9/.lock" — timed out after 60002ms', 'tasks.publish_lock_timeout'],
    'a step that ran out of time' => ['Run workspace phase', 'Run workspace phase timed out after 1800s', 'tasks.publish_failed'],
    'a lock line inside other output' => ['Run workspace phase', "fatal: x\n".'Lock "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp/.swamp/data/command/shell/d52d75df-895d-4824-92c3-7bc1fea709d9/.lock" held by orbit@swamp (pid 3018532) — timed out after 60002ms', 'tasks.publish_failed'],
    'a lock that is no Swamp lock file' => ['Run workspace phase', 'Lock "/var/lib/dpkg/lock" held by root (pid 7) — timed out after 60002ms', 'tasks.publish_failed'],
    'a push step that ran out of time' => ['Push the branch', 'Push the branch timed out after 60s', 'tasks.push_failed'],
    // A lock timeout of the base check is a lock timeout, not a conflict: the tick retries it.
    'the base check' => ['Check the base', 'Lock "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp/.swamp/data/command/shell/d52d75df-895d-4824-92c3-7bc1fea709d9/.lock" held by orbit@swamp (pid 3018532) — timed out after 60002ms', 'tasks.publish_lock_timeout'],
]);

/** A copy publish admitted and running, as its run's terminal event finds it. */
function runningCopyPublish(): array
{
    [$group, $check] = copyPublishWorld();
    $operation = app(TaskPublishIntent::class)->start($group, StartTaskPublishData::from(['check' => $check->id]), 'test', null);
    $run = '6b69badf-4f06-4f2e-82d7-21b62c1f587a';
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run]]);

    return [$group, $operation->refresh(), $run];
}

it('states the base of a copy publish in its result and publishes, behind or not, and through a merge at publish', function (int $behind, array $overlap, ?string $merged): void {
    [$group, $operation, $run] = runningCopyPublish();
    $base = ['base_commit' => str_repeat('a', 40), 'default_commit' => str_repeat('c', 40), 'behind_by' => $behind, 'overlapping_paths' => $overlap, 'overlapping_more' => 0,
        'merged_default' => $merged];

    $projected = app(TaskPublishProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'succeeded', 'started_at' => now()->subMinute()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['base' => json_encode($base),
            'pull_receipt' => json_encode(['pull_request_number' => 7, 'pull_request_url' => 'https://github.com/example/trial/pull/7'])]]);

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['branch' => 'task-'.$group->id, 'commit' => str_repeat('b', 40), 'pull_request_number' => 7,
            'pull_request_url' => 'https://github.com/example/trial/pull/7', ...$base])
        ->and($group->refresh()->pr_url)->toBe('https://github.com/example/trial/pull/7');
})->with([
    'level with the default branch' => [0, [], null],
    'behind, no path changed on both sides' => [3, [], null],
    'behind, a path changed on both sides without a conflict' => [2, ['app/Models/Order.php'], null],
    // The record keeps the checked commit; the result names the head the worker merged (docs/reference/tasks.md#merge-main-at-publish).
    'behind a workflow change of the default branch, merged at publish' => [1, [], str_repeat('c', 40)],
]);

it('records when GitHub opened the published pull request, and never moves a time already recorded', function (?string $recorded, string $expected): void {
    [$group, $operation, $run] = runningCopyPublish();
    $group->update(['pr_opened_at' => $recorded]);

    app(TaskPublishProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'succeeded', 'started_at' => now()->subMinute()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['pull_receipt' => json_encode(['pull_request_number' => 7,
            'pull_request_url' => 'https://github.com/example/trial/pull/7', 'pull_request_created_at' => '2026-11-19T10:00:00Z'])]]);

    expect($group->refresh()->pr_opened_at?->toIso8601String())->toBe($expected)
        ->and($operation->refresh()->result)->not->toHaveKey('pull_request_created_at');
})->with([
    'none recorded' => [null, '2026-11-19T10:00:00+00:00'],
    'one recorded' => ['2026-11-18T09:00:00Z', '2026-11-18T09:00:00+00:00'],
]);

it('records no base for a run that started on the workflow before the base step', function (): void {
    [$group, $operation, $run] = runningCopyPublish();

    $projected = app(TaskPublishProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'succeeded', 'started_at' => now()->subMinute()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => []]);

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['branch' => 'task-'.$group->id, 'commit' => str_repeat('b', 40), 'pull_request_number' => null, 'pull_request_url' => null]);
});

it('refuses a real conflict with the default branch, names its paths and publishes nothing', function (): void {
    [$group, $operation, $run] = runningCopyPublish();

    $projected = app(TaskPublishProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => [],
        'failure' => ['failed_step' => 'Check the base', 'detail' => 'tasks.publish_conflict: "app/Order.php", "docs/a, b.md" and 3 more']]);

    expect($projected->error_code)->toBe('tasks.publish_conflict')
        ->and($projected->result)->toBe(['error_code' => 'tasks.publish_conflict', 'conflicting_paths' => ['app/Order.php', 'docs/a, b.md'], 'conflicting_more' => 3])
        ->and($group->refresh()->pr_url)->toBeNull();
});

it('fails a base read that could not run without the conflict code', function (): void {
    [, $operation, $run] = runningCopyPublish();

    $projected = app(TaskPublishProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => [], 'failure' => ['failed_step' => 'Check the base', 'detail' => 'tasks.publish_failed']]);

    expect($projected->error_code)->toBe('tasks.publish_failed')
        ->and($projected->result)->toBe(['error_code' => 'tasks.publish_failed']);
});

it('keeps a long conflict through the stored step failure, with its code and its bounded paths', function (): void {
    [$group, $operation, $run] = runningCopyPublish();
    // "Check the base" names at most 3,000 bytes of paths and the count of the rest; the run's step error carries that line after
    // other output, and the Gateway keeps the last 4,096 bytes of it, without an incomplete first line.
    $paths = array_map(static fn (int $index): string => "apps/gateway/app/Domain/Tasks/Long/Folder/Name/Changed{$index}.php", range(0, 299));
    $shown = [];
    $used = 2;
    foreach ($paths as $path) {
        $size = strlen(json_encode($path, JSON_UNESCAPED_SLASHES)) + ($shown === [] ? 0 : 2);
        if ($used + $size > 3000 - strlen('tasks.publish_conflict: ') - strlen(' and 300 more')) {
            break;
        }
        $shown[] = $path;
        $used += $size;
    }
    $line = 'tasks.publish_conflict: '.implode(', ', array_map(static fn (string $path): string => json_encode($path, JSON_UNESCAPED_SLASHES), $shown))
        .' and '.(300 - count($shown)).' more';
    $error = str_repeat("deno: an earlier line of the step's output\n", 60).$line;
    $failure = app(NativeStepFailure::class)->fromRun(['status' => 'failed', 'jobs' => [['steps' => [
        ['name' => 'Check the base', 'status' => 'failed', 'error' => $error, 'completedAt' => now()->toIso8601String()],
    ]]]], $operation);

    $projected = app(TaskPublishProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => [], 'failure' => $failure]);

    expect(strlen($line))->toBeLessThanOrEqual(3000)
        ->and($projected->error_code)->toBe('tasks.publish_conflict')
        ->and($projected->result)->toBe(['error_code' => 'tasks.publish_conflict', 'conflicting_paths' => $shown, 'conflicting_more' => 300 - count($shown)])
        ->and($group->refresh()->pr_url)->toBeNull();
});

it('keeps the Swamp start fixture equal to the inputs a copy publish starts with', function (): void {
    app()->instance(WebSocketClient::class, WorkflowStartIntents::vault());
    [$group, $check] = copyPublishWorld();
    $operation = app(TaskPublishIntent::class)->start($group, StartTaskPublishData::from(['check' => $check->id,
        'pull_request' => ['title' => 'Export orders', 'body' => 'Adds the export.']]), 'test', null);
    WorkflowStartIntents::match('publish_copy', app(DirectOperation::class)->workflowInputs($operation));
});

// The task VM publish is removed with the task VMs (docs/reference/old-paths.md): a group whose workspace sat on a task VM has no task copy.
it('refuses a publish of a group whose workspace is on a task VM by name, and starts nothing', function (): void {
    [$vmGroup, $vmCheck] = vmPublishWorld();

    expect(fn () => app(TaskPublishIntent::class)->start($vmGroup, StartTaskPublishData::from(['check' => $vmCheck->id,
        'pull_request' => ['title' => 'Export orders', 'body' => 'Adds the export.']]), 'test', null))
        ->toThrow(fn (ResourceOperationException $refusal) => expect([$refusal->errorCode, $refusal->getMessage()])->toBe(['tasks.no_workspace', 'The group requires its task copy.']))
        ->and(Operation::query()->where('kind', 'tasks.publish')->exists())->toBeFalse();
});

/**
 * A running task VM group whose owned checkout has a passed handoff check, as a task VM publish admits it.
 *
 * @return array{Task, TaskCheck}
 */
function vmPublishWorld(): array
{
    SwampNodeFixture::ensure();
    Queue::fake([StartAdmittedSwampRun::class]);
    app(TaskExtensionState::class)->enable();
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $guest = $node('tvm-598', '10.44.0.137');
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'orchestration' => 'external', 'status' => TaskGroupStatus::Running]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Active]);
    $group->update(['taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id]);
    $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Change', 'brief' => 'Change it.', 'status' => 'todo']);
    $check = TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'pid' => 1, 'process_started' => 'x',
        'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('2', 40), 'head_after' => str_repeat('b', 40), 'tree_after' => str_repeat('4', 40),
        'commit_sha' => str_repeat('b', 40), 'exit_code' => 0, 'output' => '', 'started_at' => now(), 'finished_at' => now()]);
    Operation::query()->create(['kind' => OperationKinds::TasksCheck, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'input' => ['subtask' => $subtask->id, 'phase' => 'handoff', 'check_id' => $check->id], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1,
        'result' => ['outcome' => 'passed', 'check_id' => $check->id, 'commit' => $check->commit_sha, 'head' => $check->head_after, 'tree' => $check->tree_after, 'ci' => null]]);

    return [$group->refresh(), $check];
}
