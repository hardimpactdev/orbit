<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Requests\Tasks\CheckTaskRequest;
use Orbit\Sdk\Requests\Tasks\CompleteTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskRequest;
use Orbit\Sdk\Requests\Tasks\ListTasksRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskRequest;
use Orbit\Sdk\Requests\Tasks\TurnTaskRequest;
use Orbit\Sdk\Requests\Tasks\UpdateTaskRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

/**
 * A task with no subtask holds its own deliverables and takes the subtask calls by its own ID
 * (docs/reference/tasks.md#a-task-with-no-subtask).
 */
beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    $this->orbitHome = sys_get_temp_dir().'/orbit-tasks-'.Str::uuid();
    mkdir($this->orbitHome, 0700, true);
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/tmp/test-ca.pem'));
    $this->deliverables = $this->orbitHome.'/deliverables.json';
    file_put_contents($this->deliverables, '[{"id":"sdk-test","type":"command","description":"The SDK tests pass","command":"vendor/bin/pest","directory":"packages/php-sdk"}]');
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
});

/** The recorded task with no subtask, with the given fields of the task and of its work replaced. */
function task_without_subtask(array $task = [], array $work = []): MockResponse
{
    $body = json_decode(gateway_fixture('tasks/task-create/no-subtask')['body'], true, flags: JSON_THROW_ON_ERROR);
    $body['data'] = [...$body['data'], ...$task];
    $body['data']['work'] = [...$body['data']['work'], ...$work];

    return MockResponse::make($body, 200, ['Content-Type' => 'application/json']);
}

/** @return array<class-string, MockResponse> */
function task_operation_mock(string $class, string $kind): array
{
    $operation = ['id' => 42, 'kind' => $kind, 'target_type' => 'task', 'target_id' => 1, 'status' => 'succeeded', 'sequence' => 4, 'result' => ['outcome' => 'passed']];

    return [
        $class => MockResponse::make(['data' => $operation], 202),
        ShowOperationRequest::class => MockResponse::make(['data' => $operation]),
        ShowRealtimeRequest::class => MockResponse::make(['data' => ['url' => null, 'key' => null, 'channel' => 'orbit']]),
    ];
}

it('creates a task with its own deliverables and no subtask', function (): void {
    $mock = MockClient::global(gateway_fixture_mock('tasks/task-create/no-subtask'));

    expect(Artisan::call('task:create', ['title' => 'Add the tasks CLI', '--project' => '1', '--brief' => 'Brief', '--status' => 'todo', '--deliverables' => $this->deliverables, '--json' => true]))->toBe(0);

    $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskRequest
        && (string) $request->body() === '{"project_id":1,"title":"Add the tasks CLI","brief":"Brief","deliverables":[{"id":"sdk-test","type":"command","description":"The SDK tests pass","command":"vendor\/bin\/pest","directory":"packages\/php-sdk"}],"status":"todo"}');
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($json['subtasks'])->toBe([])
        ->and($json['work'])->toMatchArray(['id' => 2, 'type' => 'work', 'status' => 'todo'])
        ->and(array_column($json['work']['deliverables'], 'id'))->toBe(['sdk-test']);
});

it('refuses an unreadable deliverables file of a task before any request', function (): void {
    $mock = MockClient::global([]);

    expect(Artisan::call('task:create', ['title' => 'T', '--project' => '1', '--brief' => 'B', '--deliverables' => $this->orbitHome.'/missing.json', '--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('task.deliverables_invalid');

    $mock->assertNothingSent();
});

it('checks and turns a task with no subtask by its own ID', function (): void {
    $mock = MockClient::global(task_operation_mock(CheckTaskRequest::class, 'tasks.check'));
    expect(Artisan::call('task:check', ['task' => '1', '--phase' => 'handoff', '--json' => true]))->toBe(0);
    $mock->assertSent(static fn (Request $request): bool => $request instanceof CheckTaskRequest
        && $request->resolveEndpoint() === '/api/v1/tasks/1/check' && (string) $request->body() === '{"phase":"handoff"}');

    MockClient::destroyGlobal();
    $mock = MockClient::global(task_operation_mock(TurnTaskRequest::class, 'tasks.turn'));
    expect(Artisan::call('task:turn', ['task' => '1', '--role' => 'reviewer', '--prompt' => 'Review it.', '--check' => '9', '--json' => true]))->toBe(0);
    $mock->assertSent(static fn (Request $request): bool => $request instanceof TurnTaskRequest
        && $request->resolveEndpoint() === '/api/v1/tasks/1/turn'
        && json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR) === ['role' => 'reviewer', 'prompt' => 'Review it.', 'check' => 9]);
});

it('moves the work of a task with no subtask by --work-status, apart from the task status', function (): void {
    $mock = MockClient::global([UpdateTaskRequest::class => task_without_subtask(['status' => 'running'], ['status' => 'completed'])]);

    expect(Artisan::call('task:update', ['task' => '1', '--work-status' => 'completed', '--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['work']['status'])->toBe('completed');

    $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateTaskRequest
        && $request->resolveEndpoint() === '/api/v1/tasks/1' && (string) $request->body() === '{"work_status":"completed"}');
});

it('refuses an unknown work status before any request', function (): void {
    $mock = MockClient::global([]);

    expect(Artisan::call('task:update', ['task' => '1', '--work-status' => 'settling', '--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('task.work_status_invalid');

    $mock->assertNothingSent();
});

it('corrects the deliverables of a task with no subtask with the reason', function (): void {
    $mock = MockClient::global([UpdateTaskRequest::class => task_without_subtask(['status' => 'running'], ['status' => 'running'])]);

    expect(Artisan::call('task:update', ['task' => '1', '--deliverables' => $this->deliverables, '--reason' => 'The SDK tests run from the package.', '--json' => true]))->toBe(0);

    $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateTaskRequest
        && json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR)['reason'] === 'The SDK tests run from the package.'
        && array_column(json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR)['deliverables'], 'id') === ['sdk-test']);
});

it('completes a task with no subtask and shows its ended work', function (): void {
    $mock = MockClient::global([
        ShowTaskRequest::class => task_without_subtask(['status' => 'settling'], ['status' => 'completed']),
        CompleteTaskGroupRequest::class => task_without_subtask(['status' => 'completed'], ['status' => 'completed']),
    ]);

    expect(Artisan::call('task:complete', ['task' => '1', '--yes' => true]))->toBe(0);
    $output = Artisan::output();

    $mock->assertSent(static fn (Request $request): bool => $request instanceof CompleteTaskGroupRequest && $request->resolveEndpoint() === '/api/v1/tasks/1/complete');
    expect($output)->toContain('Work status')
        ->and($output)->toContain('sdk-test')
        ->and($output)->not->toContain('No subtasks.');
});

it('lists a task with no subtask without a subtask count', function (): void {
    $list = json_decode(gateway_fixture('tasks/task-list/default')['body'], true, flags: JSON_THROW_ON_ERROR);
    $task = json_decode(gateway_fixture('tasks/task-create/no-subtask')['body'], true, flags: JSON_THROW_ON_ERROR)['data'];
    $list['data'] = [[...$task, 'id' => 7, 'title' => 'Standalone task', 'work' => [...$task['work'], 'id' => 8, 'parent_id' => 7, 'status' => 'completed']]];
    MockClient::global([...gateway_fixture_mock(), ListTasksRequest::class => MockResponse::make($list, 200, ['Content-Type' => 'application/json'])]);

    expect(Artisan::call('task:list', ['--no-interaction' => true]))->toBe(0);
    $row = Str::of(Artisan::output())->explode("\n")->first(static fn (string $line): bool => str_contains($line, 'Standalone task'));

    expect($row)->not->toBeNull()->and($row)->toContain('—')->and($row)->not->toContain('0/0')->and($row)->not->toContain('1/1');
});

it('shows the latest check of a task with no subtask, and sends its read deliverables back with their paths', function (): void {
    MockClient::global(gateway_fixture_mock('tasks/task-show/no-subtask'));

    expect(Artisan::call('task:show', ['task' => '1', '--json' => true]))->toBe(0);
    $work = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['work'];
    expect($work['check'])->toMatchArray(['id' => 19, 'kind' => 'handoff', 'status' => 'passed'])
        ->and($work['deliverables'][0])->toMatchArray(['fails_on_base' => true, 'paths' => ['packages/php-sdk/tests/Unit/Requests/Tasks/TaskRequestsTest.php']]);

    file_put_contents($this->deliverables, json_encode($work['deliverables'], JSON_THROW_ON_ERROR));
    MockClient::destroyGlobal();
    $mock = MockClient::global([UpdateTaskRequest::class => task_without_subtask(['status' => 'running'], ['status' => 'running'])]);

    expect(Artisan::call('task:update', ['task' => '1', '--deliverables' => $this->deliverables, '--reason' => 'Retry the check.', '--json' => true]))->toBe(0);

    $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateTaskRequest
        && json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR)['deliverables'] === $work['deliverables']);
});

it('lists a task with no subtask with the ID and status of its latest check', function (): void {
    $list = json_decode(gateway_fixture('tasks/task-list/default')['body'], true, flags: JSON_THROW_ON_ERROR);
    $task = json_decode(gateway_fixture('tasks/task-show/no-subtask')['body'], true, flags: JSON_THROW_ON_ERROR)['data'];
    unset($task['work']['check']['output'], $task['publication']);
    $list['data'] = [$task];
    MockClient::global([...gateway_fixture_mock(), ListTasksRequest::class => MockResponse::make($list, 200, ['Content-Type' => 'application/json'])]);

    expect(Artisan::call('task:list', ['--json' => true]))->toBe(0);

    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['task_groups'][0]['work']['check'])->toBe($task['work']['check']);
});
