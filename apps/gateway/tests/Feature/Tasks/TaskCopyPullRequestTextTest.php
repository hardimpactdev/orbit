<?php

declare(strict_types=1);

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Actions\Tasks\RecordTaskPullRequestTextAction;
use App\Actions\Tasks\RunTaskPublishWhenDoneAction;
use App\Actions\Tasks\StartTaskPublishAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\StartTaskPublishData;
use App\Data\Tasks\TaskGroupData;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskDonePublication;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrMode;
use App\Http\Requests\Tasks\CreateTaskGroupRequest;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TaskCopyDoneFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    Queue::fake([StartAdmittedSwampRun::class]);
    app(TaskExtensionState::class)->enable();
});

/** The copy key that the copy's model environment carries, as its create writes it. */
function copyKeyOf(TaskCopyDoneFixture $world): string
{
    $environment = app(InstanceContainer::class)->modelEnvironment(InstanceRuntime::query()->where('instance_id', $world->instance->id)->sole(), 'placeholder');
    preg_match('/^ORBIT_COPY_KEY=([0-9a-f]{64})$/m', $environment, $match);

    return $match[1] ?? throw new LogicException('The model environment holds no copy key.');
}

/** The reviewer's turn on the done check, still running: the one moment the copy may send its text. */
function copyReviewerTurnRuns(TaskCopyDoneFixture $world): Operation
{
    return Operation::query()->create(['kind' => OperationKinds::TasksCopyTurn, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $world->group->id,
        'node_id' => null, 'input' => ['subtask' => $world->subtask->id, 'role' => 'reviewer', 'thread_id' => $world->reviewer->id, 'check' => $world->check->id,
            'candidate' => TaskCopyDoneFixture::Commit], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1]);
}

/** @param array<string, string> $text */
function copySends(string $key, array $text, string $from = '10.44.0.71'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $from])->withToken($key)->postJson('/api/v1/copy/pull-request', $text);
}

it('writes a new copy key into a task copy\'s model environment each time, and keeps only the newest key\'s hash', function (): void {
    $world = TaskCopyDoneFixture::make(text: false);
    $runtime = InstanceRuntime::query()->where('instance_id', $world->instance->id)->sole();

    $environment = app(InstanceContainer::class)->modelEnvironment($runtime, 'placeholder');
    $first = copyKeyOf($world);
    $second = copyKeyOf($world);

    expect($environment)->toContain("ORBIT_MODEL_KEY=placeholder\n")->toContain("ORBIT_COPY_API=http://10.253.0.1:8319/orbit/v1/copy\n")
        ->and($first)->not->toBe($second)
        ->and($runtime->refresh()->agent_key_hash)->toBe(hash('sha256', $second))
        ->and($runtime->toArray())->not->toHaveKey('agent_key_hash');
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.71'])->withToken($first)->getJson('/api/v1/copy/whoami')
        ->assertStatus(401)->assertJsonPath('error.code', 'copy.key_invalid');
});

it('answers the copy\'s own key from the copy\'s own Node, and nothing else', function (): void {
    $world = TaskCopyDoneFixture::make(text: false);
    $key = copyKeyOf($world);
    $other = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'shark', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $whoami = fn (?string $bearer, string $from): TestResponse => $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $from])
        ->withHeaders($bearer === null ? [] : ['Authorization' => 'Bearer '.$bearer])->getJson('/api/v1/copy/whoami');

    $whoami($key, '10.44.0.71')->assertOk()->assertJsonPath('data', ['instance_id' => $world->instance->id, 'project_id' => $world->group->project_id,
        'group_id' => $world->group->id]);
    // The Node alone is no key: every container on it calls from its address.
    $whoami(null, '10.44.0.71')->assertStatus(401)->assertJsonPath('error.code', 'copy.key_invalid');
    $whoami(str_repeat('0', 64), '10.44.0.71')->assertStatus(401)->assertJsonPath('error.code', 'copy.key_invalid');
    // A key that left its Node is useless, also from another active Node.
    $whoami($key, $other->wireguard_ip)->assertStatus(403)->assertJsonPath('error.code', 'copy.peer_mismatch');
    $whoami($key, '203.0.113.9')->assertStatus(403)->assertJsonPath('error.code', 'copy.peer_mismatch');
});

it('records the reviewer\'s text on the check of its running turn, and the publish of the done group opens the pull request with it', function (): void {
    $world = TaskCopyDoneFixture::make(text: false);
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone, 'issue_number' => 9]);
    $key = copyKeyOf($world);
    $turn = copyReviewerTurnRuns($world);

    copySends($key, ['title' => 'First try', 'body' => 'Draft.'])->assertStatus(202);
    // A second send in the same turn replaces the text; form fields work as JSON does.
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.71'])->withToken($key)
        ->post('/api/v1/copy/pull-request', ['title' => 'Keep every field', 'body' => "Type and website were lost.\n"])
        ->assertStatus(202)->assertJsonPath('data', ['state' => 'recorded', 'check_id' => $world->check->id, 'operation_id' => $turn->id, 'opens' => 'when the group is done']);
    $turn->update(['status' => OperationStatus::Succeeded, 'result' => ['state' => 'done', 'ended' => true], 'finished_at' => now()]);

    app(RunTaskPublishWhenDoneAction::class)->execute();

    expect($world->check->refresh()->pr_text_operation_id)->toBe($turn->id)
        ->and(Operation::query()->where('kind', OperationKinds::TasksPublish)->sole()->input['pull_request'])
        ->toBe(['title' => 'Keep every field', 'body' => "Type and website were lost.\n\nCloses #9", 'draft' => false]);
});

it('records the reviewer\'s text after the pull request opened, and the republish of the done check updates the pull request with it', function (bool $named): void {
    $world = TaskCopyDoneFixture::make(TaskPrMode::ReviewBeforePr, text: false);
    $world->group->update(['pr_url' => 'https://github.com/example/trial/pull/7', 'issue_number' => 9]);
    $key = copyKeyOf($world);
    // A CI-fix round: a new reviewer turn on the done check, after the first publish opened the pull request.
    $turn = copyReviewerTurnRuns($world);

    copySends($key, ['title' => 'Keep every field', 'body' => 'Round two fixed CI.'])
        ->assertStatus(202)->assertJsonPath('data', ['state' => 'recorded', 'check_id' => $world->check->id, 'operation_id' => $turn->id,
            'opens' => RecordTaskPullRequestTextAction::UpdatesOnRepublish]);
    $turn->update(['status' => OperationStatus::Succeeded, 'result' => ['state' => 'done', 'ended' => true], 'finished_at' => now()]);

    $operation = app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData($named ? $world->check->id : null), 'person', null);

    expect($world->check->refresh()->pr_text_operation_id)->toBe($turn->id)
        ->and($operation->input['check'])->toBe($world->check->id)
        ->and($operation->input['pull_request'])->toBe(['title' => 'Keep every field', 'body' => "Round two fixed CI.\n\nCloses #9", 'draft' => false]);
})->with(['a bare publish' => [false], 'a publish that names the done check' => [true]]);

it('refuses the text outside a running reviewer turn on the checked commit, for a held Project, an ended group and a bad title', function (Closure $arrange, array $text, int $status, string $code): void {
    $world = TaskCopyDoneFixture::make(text: false);
    $key = copyKeyOf($world);
    $arrange($world);

    copySends($key, $text)->assertStatus($status)->assertJsonPath('error.code', $code);
    expect($world->check->refresh()->pr_title)->toBeNull();
})->with([
    'no turn runs' => [fn (TaskCopyDoneFixture $w) => null, ['title' => 'T', 'body' => 'B'], 409, 'tasks.pr_text_not_reviewer_turn'],
    'an implementer turn runs' => [fn (TaskCopyDoneFixture $w) => Operation::query()->create(['kind' => OperationKinds::TasksCopyTurn, 'target_type' => OperationKinds::TaskGroupTarget,
        'target_id' => $w->group->id, 'node_id' => null, 'input' => ['subtask' => $w->subtask->id, 'role' => 'implementer', 'thread_id' => $w->reviewer->id + 100, 'candidate' => null],
        'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1]), ['title' => 'T', 'body' => 'B'], 409, 'tasks.pr_text_not_reviewer_turn'],
    'the check no longer passed' => [function (TaskCopyDoneFixture $w): void {
        copyReviewerTurnRuns($w);
        $w->check->update(['status' => TaskCheckStatus::Failed]);
    }, ['title' => 'T', 'body' => 'B'], 422, 'tasks.commit_not_checked'],
    'a held Project' => [function (TaskCopyDoneFixture $w): void {
        copyReviewerTurnRuns($w);
        config(['orbit.tasks.copy_publish_projects' => []]);
    }, ['title' => 'T', 'body' => 'B'], 409, 'tasks.copy_publish_held'],
    'an ended group' => [function (TaskCopyDoneFixture $w): void {
        copyReviewerTurnRuns($w);
        $w->group->update(['status' => TaskGroupStatus::Cancelled]);
    }, ['title' => 'T', 'body' => 'B'], 409, 'tasks.group_closed'],
    'a title of two lines' => [copyReviewerTurnRuns(...), ['title' => "T\nU", 'body' => 'B'], 422, 'validation.failed'],
    'a body too long in UTF-16 code units' => [copyReviewerTurnRuns(...), ['title' => 'T', 'body' => str_repeat('😀', intdiv(TaskDonePublication::ReviewerBodyLimit, 2) + 1)], 422, 'validation.failed'],
    'a body too long' => [copyReviewerTurnRuns(...), ['title' => 'T', 'body' => str_repeat('b', TaskDonePublication::ReviewerBodyLimit + 1)], 422, 'validation.failed'],
]);

it('stores the issue a new group names, returns it on task:show, and accepts only an issue number', function (): void {
    $project = Project::query()->create(['name' => 'Issues', 'slug' => 'issues', 'repository_url' => 'https://github.com/example/issues.git', 'default_branch' => 'main', 'root' => '.']);
    $rules = collect((new CreateTaskGroupRequest)->rules())->only(['title', 'brief', 'issue'])->all();

    $group = app(CreateTaskGroupAction::class)->execute(new CreateTaskGroupData(projectId: $project->id, title: 'T', brief: 'B', status: TaskGroupStatus::Backlog, tasks: [], issue: 9));

    expect($group->refresh()->issue_number)->toBe(9)
        ->and(TaskGroupData::fromModel($group)->toArray()['issue'])->toBe(9)
        ->and(Validator::make(['title' => 'T', 'brief' => 'B', 'issue' => 9], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['title' => 'T', 'brief' => 'B', 'issue' => 0], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['title' => 'T', 'brief' => 'B', 'issue' => '9'], $rules)->fails())->toBeTrue();
});
