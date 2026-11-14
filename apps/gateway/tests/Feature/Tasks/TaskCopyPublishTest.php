<?php

declare(strict_types=1);

use App\Data\Tasks\StartTaskPublishData;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPublishIntent;
use App\Domain\Tasks\TaskPublishProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
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
        ->and(TaskVm::query()->count())->toBe(0)
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
