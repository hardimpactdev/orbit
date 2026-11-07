<?php

declare(strict_types=1);

use App\Commands\Tasks\Concerns\ConfirmsTaskChanges;
use App\Commands\Tasks\TaskCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\CommandPrompts;
use App\Support\Console\ConsoleMode;
use App\Support\Console\PromptAborted;
use App\Support\Console\PromptContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Projects\ListProjectsRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Requests\Tasks\CancelTaskRequest;
use Orbit\Sdk\Requests\Tasks\CloseTaskQuestionRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskCommentRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskAgentsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskCommentsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskEgressRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskQuestionsRequest;
use Orbit\Sdk\Requests\Tasks\ListTasksRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskAgentRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskRequest;
use Orbit\Sdk\Requests\Tasks\ShowTasksStatusRequest;
use Orbit\Sdk\Requests\Tasks\UpdateTaskRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-tasks-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/tmp/test-ca.pem'));

    MockClient::global(gateway_fixture_mock());
    app(GatewayExtensionState::class)->discover();
    MockClient::destroyGlobal();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
});

describe('omitted and invalid input', function (): void {
    it('refuses in machine and noninteractive modes before any request', function (string $command, array $arguments, string $code): void {
        foreach ([['--json' => true], ['--no-interaction' => true]] as $mode) {
            $mock = MockClient::global([]);

            expect(Artisan::call($command, [...$arguments, ...$mode]))->toBe(1);

            if (isset($mode['--json'])) {
                expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($code);
            } else {
                $output = Artisan::output();

                expect($output)->not->toBe('');

                if ($code === 'task.since_invalid') {
                    expect($output)->toContain('Since must be an ISO 8601 date or time.');
                }
            }

            $mock->assertNothingSent();
            MockClient::destroyGlobal();
        }
    })->with([
        'show without group' => ['task:show', [], 'task.task_required'],
        'show with invalid group' => ['task:show', ['task' => 'abc'], 'task.task_invalid'],
        'agents without group' => ['task:agent:list', [], 'task.task_required'],
        'egress without group' => ['task:egress', [], 'task.task_required'],
        'agent show without group' => ['task:agent:show', [], 'task.task_required'],
        'agent show without thread' => ['task:agent:show', ['task' => '1'], 'task.agent_thread_required'],
        'agent show with invalid thread' => ['task:agent:show', ['task' => '1', 'session' => 'x'], 'task.agent_thread_invalid'],
        'list with invalid project' => ['task:list', ['--project' => '0'], 'task.project_invalid'],
        'list with unknown status' => ['task:list', ['--status' => 'queued'], 'task.status_invalid'],
        'questions with invalid project' => ['task:question:list', ['--project' => '0'], 'task.project_invalid'],
        'questions with unknown cause' => ['task:question:list', ['--cause' => 'nope'], 'task.cause_invalid'],
        'questions with unknown status' => ['task:question:list', ['--status' => 'queued'], 'task.status_invalid'],
        'questions with a relative since' => ['task:question:list', ['--since' => 'yesterday'], 'task.since_invalid'],
        'questions with an impossible date' => ['task:question:list', ['--since' => '2026-02-31'], 'task.since_invalid'],
        'questions with an offset minute of 99' => ['task:question:list', ['--since' => '2026-10-07T12:00:00+01:99'], 'task.since_invalid'],
        'questions with an offset hour of 99' => ['task:question:list', ['--since' => '2026-10-07T12:00:00+99:00'], 'task.since_invalid'],
        'questions with a fractional minute of 60' => ['task:question:list', ['--since' => '2026-10-07T12:60.5Z'], 'task.since_invalid'],
        'questions with a fractional minute of 99' => ['task:question:list', ['--since' => '2026-10-07T12:99.5Z'], 'task.since_invalid'],
        'question close without question' => ['task:question:close', ['--status' => 'superseded', '--reason' => 'Stale.'], 'task.question_required'],
        'question close with invalid question' => ['task:question:close', ['question' => 'abc', '--status' => 'superseded', '--reason' => 'Stale.'], 'task.question_invalid'],
        'question close without status' => ['task:question:close', ['question' => '98', '--reason' => 'Stale.'], 'task.status_required'],
        'question close with an open status' => ['task:question:close', ['question' => '98', '--status' => 'open', '--reason' => 'Stale.'], 'task.status_invalid'],
        'question close without reason' => ['task:question:close', ['question' => '98', '--status' => 'superseded'], 'task.reason_required'],
        'question close with a long reason' => ['task:question:close', ['question' => '98', '--status' => 'superseded', '--reason' => str_repeat('x', 2001)], 'task.reason_invalid'],
        'create without project' => ['task:create', ['title' => 'T', '--brief' => 'B'], 'task.project_required'],
        'create without title' => ['task:create', ['--project' => '1', '--brief' => 'B'], 'task.title_required'],
        'create without brief' => ['task:create', ['title' => 'T', '--project' => '1'], 'task.brief_required'],
        'create with a long title' => ['task:create', ['title' => str_repeat('x', 161), '--project' => '1', '--brief' => 'B'], 'task.title_invalid'],
        'create with a blank brief' => ['task:create', ['title' => 'T', '--project' => '1', '--brief' => '   '], 'task.brief_invalid'],
        'create with a running status' => ['task:create', ['title' => 'T', '--project' => '1', '--brief' => 'B', '--status' => 'running'], 'task.status_invalid'],
        'create with a missing subtasks file' => ['task:create', ['title' => 'T', '--project' => '1', '--brief' => 'B', '--subtasks' => '/nonexistent/subtasks.json'], 'task.subtasks_invalid'],
        'update without a change' => ['task:update', ['task' => '1'], 'task.update_required'],
        'update with an unknown status' => ['task:update', ['task' => '1', '--status' => 'unknown'], 'task.status_invalid'],
        'subtask update with an unknown status' => ['task:update', ['task' => '2', '--status' => 'unknown'], 'task.status_invalid'],
        'cancel without consent' => ['task:cancel', ['task' => '1'], 'input.confirmation_required'],
        'complete without consent' => ['task:complete', ['task' => '1'], 'input.confirmation_required'],
        'subtask create without brief' => ['task:create', ['--parent' => '1', 'title' => 'T'], 'task.brief_required'],
        'subtask update without subtask' => ['task:update', ['--title' => 'T'], 'task.task_required'],
        'subtask update without a change' => ['task:update', ['task' => '2'], 'task.update_required'],
        'subtask update with position zero' => ['task:update', ['task' => '2', '--position' => '0'], 'task.position_invalid'],
        'subtask cancel without consent' => ['task:cancel', ['task' => '2'], 'input.confirmation_required'],
        'subtask cancel with an invalid subtask' => ['task:cancel', ['task' => 'x', '--yes' => true], 'task.task_invalid'],
        'subtask destroy without consent' => ['task:destroy', ['task' => '2'], 'input.confirmation_required'],
        'group comment without group' => ['task:comment:create', [], 'task.task_required'],
        'group comments without group' => ['task:comment:list', [], 'task.task_required'],
        'group comment without type' => ['task:comment:create', ['task' => '1', '--body' => 'B', '--author' => 'driver'], 'task.comment_type_required'],
        'group comment with unknown type' => ['task:comment:create', ['task' => '1', '--type' => 'approved', '--body' => 'B', '--author' => 'driver'], 'task.comment_type_invalid'],
        'group comment without body' => ['task:comment:create', ['task' => '1', '--type' => 'resolution', '--author' => 'nick'], 'task.comment_body_required'],
        'group comment with blank body' => ['task:comment:create', ['task' => '1', '--type' => 'resolution', '--body' => '  ', '--author' => 'nick'], 'task.comment_body_invalid'],
        'group comment without author' => ['task:comment:create', ['task' => '1', '--type' => 'resolution', '--body' => 'B'], 'task.comment_author_required'],
        'comment without type' => ['task:comment:create', ['task' => '2', '--body' => 'B', '--author' => 'nick'], 'task.comment_type_required'],
        'comment with an unknown type' => ['task:comment:create', ['task' => '2', '--type' => 'approved', '--body' => 'B', '--author' => 'nick'], 'task.comment_type_invalid'],
        'comment without body' => ['task:comment:create', ['task' => '2', '--type' => 'resolution', '--author' => 'nick'], 'task.comment_body_required'],
        'comment without author' => ['task:comment:create', ['task' => '2', '--type' => 'resolution', '--body' => 'B'], 'task.comment_author_required'],
        'comment with an invalid thread' => ['task:comment:create', ['task' => '2', '--type' => 'resolution', '--body' => 'B', '--author' => 'nick', '--agent-thread' => 'x'], 'task.agent_thread_invalid'],
        'comment list without subtask' => ['task:comment:list', [], 'task.task_required'],
    ]);

    it('refuses a subtasks file that is not an array of titled briefs', function (string $contents): void {
        $path = $this->orbitHome.'/subtasks.json';
        is_dir($this->orbitHome) || mkdir($this->orbitHome, 0700, true);
        file_put_contents($path, $contents);
        $mock = MockClient::global([]);

        expect(Artisan::call('task:create', ['title' => 'T', '--project' => '1', '--brief' => 'B', '--subtasks' => $path, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('task.subtasks_invalid');
        $mock->assertNothingSent();
    })->with([
        'not JSON' => ['title: one'],
        'an object' => ['{"title": "One", "brief": "First."}'],
        'an entry without a brief' => ['[{"title": "One"}]'],
        'an empty title' => ['[{"title": "", "brief": "First."}]'],
        'too many entries' => [json_encode(array_fill(0, 51, ['title' => 'T', 'brief' => 'B']), JSON_THROW_ON_ERROR)],
    ]);
});

describe('requests', function (): void {
    it('creates a group with the subtasks file, status, and notification', function (): void {
        $path = $this->orbitHome.'/subtasks.json';
        is_dir($this->orbitHome) || mkdir($this->orbitHome, 0700, true);
        file_put_contents($path, '[{"title": "One", "brief": "First."}, {"title": "Two", "brief": "Second."}]');
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-create/created'));

        expect(Artisan::call('task:create', ['title' => 'Add the tasks CLI', '--project' => '1', '--brief' => 'Brief', '--status' => 'todo', '--subtasks' => $path, '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskRequest
            && (string) $request->body() === '{"project_id":1,"title":"Add the tasks CLI","brief":"Brief","status":"todo","subtasks":[{"title":"One","brief":"First."},{"title":"Two","brief":"Second."}]}');
    });

    it('omits the status and notification that the caller did not supply', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-create/created'));

        expect(Artisan::call('task:create', ['title' => 'Add the tasks CLI', '--project' => '1', '--brief' => 'Brief', '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskRequest
            && (string) $request->body() === '{"project_id":1,"title":"Add the tasks CLI","brief":"Brief"}');
    });

    it('sends only the supplied update fields', function (): void {
        $mock = MockClient::global([
            ...gateway_fixture_mock('tasks/task-update/updated'),
            ...gateway_fixture_mock('tasks/task-update/child-updated'),
        ]);

        expect(Artisan::call('task:update', ['task' => '13', '--status' => 'todo', '--json' => true]))->toBe(0)
            ->and(Artisan::call('task:update', ['task' => '57', '--position' => '3', '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateTaskRequest
            && $request->resolveEndpoint() === '/api/v1/tasks/13'
            && (string) $request->body() === '{"status":"todo"}');
        $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateTaskRequest
            && $request->resolveEndpoint() === '/api/v1/tasks/57'
            && (string) $request->body() === '{"position":3}');
    });

    it('writes and reads group assistance without looking up or selecting a child', function (): void {
        $mock = MockClient::global([
            ...gateway_fixture_mock('tasks/task-comment-create/parent-requested'),
            ...gateway_fixture_mock('tasks/task-comment-list/parent-default'),
        ]);
        expect(Artisan::call('task:comment:create', ['task' => '13', '--type' => 'assistance_requested', '--body' => 'May I republish?', '--author' => 'driver', '--json' => true]))->toBe(0)
            ->and(Artisan::call('task:comment:list', ['task' => '13', '--json' => true]))->toBe(0);
        $mock->assertSentCount(2);
        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskCommentRequest
            && $request->resolveEndpoint() === '/api/v1/tasks/13/comments'
            && (string) $request->body() === '{"type":"assistance_requested","body":"May I republish?","author":"driver"}');
        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTaskCommentsRequest
            && $request->resolveEndpoint() === '/api/v1/tasks/13/comments');
    });

    it('filters the list and sends the comment thread', function (): void {
        $mock = MockClient::global([
            ...gateway_fixture_mock('tasks/task-list/default'),
            ...gateway_fixture_mock('tasks/task-comment-create/created'),
        ]);

        expect(Artisan::call('task:list', ['--project' => '4', '--status' => 'settling', '--json' => true]))->toBe(0)
            ->and(Artisan::call('task:comment:create', ['task' => '2', '--type' => 'assistance_requested', '--body' => 'Stuck.', '--author' => 'nick', '--agent-thread' => '9', '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTasksRequest
            && $request->query()->all() === ['project_id' => 4, 'status' => 'settling']);
        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskCommentRequest
            && (string) $request->body() === '{"type":"assistance_requested","body":"Stuck.","author":"nick","agent_thread_id":9}');
    });

    it('accepts the VM review wait as a list filter', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-list/default'));

        expect(Artisan::call('task:list', ['--status' => 'waiting_for_review', '--json' => true]))->toBe(0);
        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTasksRequest
            && $request->query()->all() === ['status' => 'waiting_for_review']);
    });

    it('lists the outside hosts of a task group', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-egress/default'));

        expect(Artisan::call('task:egress', ['task' => '1', '--json' => true]))->toBe(0);

        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($json['hosts'], 'destination'))->toBe(['140.82.121.4', 'example.com', 'registry.npmjs.org'])
            ->and($json['hosts'][1]['port'])->toBeNull()
            ->and($json['hosts'][2]['sources'])->toBe(['conn', 'dns'])
            ->and($json['hosts'][0])->not->toHaveKey('request_id');
        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTaskEgressRequest
            && $request->resolveEndpoint() === '/api/v1/tasks/1/egress');
    });

    it('filters the question list and renders an empty list', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-question-list/default'));

        expect(Artisan::call('task:question:list', [
            '--project' => '4',
            '--cause' => 'contract_gap',
            '--status' => 'answered',
            '--since' => '2026-10-07T00:00:00Z',
            '--json' => true,
        ]))->toBe(0);

        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['questions'])->toHaveCount(2)
            ->and($json['questions'][0]['cause'])->toBe('contract_gap')
            ->and($json['questions'][1]['answer'])->toBeNull();
        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTaskQuestionsRequest
            && $request->query()->all() === [
                'project_id' => 4,
                'cause' => 'contract_gap',
                'status' => 'answered',
                'since' => '2026-10-07T00:00:00Z',
            ]);

        MockClient::destroyGlobal();
        MockClient::global(gateway_fixture_mock('tasks/task-question-list/empty'));

        expect(Artisan::call('task:question:list'))->toBe(0);
        expect(Artisan::output())->toContain('No questions match.');
    });

    it('lists superseded questions and closes a question', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-question-list/empty'));

        expect(Artisan::call('task:question:list', ['--status' => 'superseded', '--json' => true]))->toBe(0);
        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTaskQuestionsRequest
            && $request->query()->all() === ['status' => 'superseded']);

        MockClient::destroyGlobal();
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-question-close/closed'));

        expect(Artisan::call('task:question:close', ['question' => '1', '--status' => 'superseded', '--reason' => 'A later subtask owns the continuation.', '--json' => true]))->toBe(0);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('superseded');
        $mock->assertSent(static fn (Request $request): bool => $request instanceof CloseTaskQuestionRequest
            && $request->resolveEndpoint() === '/api/v1/task-questions/1/close'
            && (string) $request->body() === '{"status":"superseded","reason":"A later subtask owns the continuation."}');
    });

    it('sends a reduced-precision since filter', function (string $since): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-question-list/empty'));

        expect(Artisan::call('task:question:list', ['--since' => $since, '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTaskQuestionsRequest
            && $request->query()->all() === ['since' => $since]);
    })->with([
        'minutes' => '2026-10-07T12:00Z',
        'hours' => '2026-10-07T12Z',
        'fractional seconds' => '2026-10-07T12:00:00.5Z',
        'offset' => '2026-10-07T12:00:00+01:00',
    ]);

    it('normalizes a fractional since cutoff before the Gateway parses it', function (string $since, string $cutoff, string $excluded): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-question-list/empty'));

        expect(Artisan::call('task:question:list', ['--since' => $since, '--json' => true]))->toBe(0);

        $sent = null;
        $mock->assertSent(static function (Request $request) use (&$sent): bool {
            if (! $request instanceof ListTaskQuestionsRequest) {
                return false;
            }

            $value = $request->query()->all()['since'] ?? null;
            $sent = is_string($value) ? $value : null;

            return is_string($sent);
        });

        $parsed = Carbon::parse($sent)->utc();
        $included = Carbon::parse($cutoff)->utc();

        expect($parsed->equalTo($included))->toBeTrue()
            ->and($included->greaterThanOrEqualTo($parsed))->toBeTrue()
            ->and(Carbon::parse($excluded)->utc()->greaterThanOrEqualTo($parsed))->toBeFalse();
    })->with([
        'fractional minutes' => ['2026-10-07T12:00.5Z', '2026-10-07T12:00:30Z', '2026-10-07T12:00:05Z'],
        'fractional hours' => ['2026-10-07T12.5Z', '2026-10-07T12:30:00Z', '2026-10-07T12:05:00Z'],
        'fractional minutes with an offset' => ['2026-10-07T12:00.5+01:00', '2026-10-07T11:00:30Z', '2026-10-07T11:00:05Z'],
    ]);

    it('cancels with --yes without reading the group first', function (): void {
        $operation = ['id' => 42, 'kind' => 'tasks.cancel', 'target_type' => 'task_group', 'target_id' => 1,
            'status' => 'succeeded', 'sequence' => 4, 'result' => ['cleanup_complete' => true]];
        $mock = MockClient::global([
            CancelTaskRequest::class => MockResponse::make(['data' => $operation], 202),
            ShowOperationRequest::class => MockResponse::make(['data' => $operation]),
            ShowRealtimeRequest::class => MockResponse::make(['data' => ['url' => null, 'key' => null, 'channel' => 'orbit']]),
        ]);

        expect(Artisan::call('task:cancel', ['task' => '1', '--yes' => true, '--json' => true]))->toBe(0);

        $mock->assertSent(CancelTaskRequest::class);
        $mock->assertNotSent(ShowTaskRequest::class);
    });

    it('cancels a running subtask with --yes without reading the group first', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-cancel/child-cancelled'));

        expect(Artisan::call('task:cancel', ['task' => '57', '--yes' => true, '--json' => true]))->toBe(0)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('cancelled');

        $mock->assertSent(static fn (Request $request): bool => $request instanceof CancelTaskRequest
            && $request->resolveEndpoint() === '/api/v1/tasks/57/cancel');
        $mock->assertNotSent(ShowTaskRequest::class);
    });

    it('sends fails_on_base and paths when creating and updating a subtask', function (bool $failsOnBase): void {
        $path = $this->orbitHome.'/deliverables.json';
        is_dir($this->orbitHome) || mkdir($this->orbitHome, 0700, true);
        file_put_contents($path, json_encode([[
            'id' => 'layout-repro',
            'type' => 'command',
            'description' => 'The layout fails before the fix',
            'command' => "vendor/bin/pest tests/Feature/HomeScreenTest.php --filter='home screen layout'",
            'directory' => 'apps/gateway',
            'fails_on_base' => $failsOnBase,
            'paths' => ['apps/gateway/tests/Feature/HomeScreenTest.php'],
        ]], JSON_THROW_ON_ERROR));
        $mock = MockClient::global([
            ...gateway_fixture_mock('tasks/task-create/child-created'),
            ...gateway_fixture_mock('tasks/task-update/child-updated'),
        ]);
        $encoded = $failsOnBase ? 'true' : 'false';

        expect(Artisan::call('task:create', ['--parent' => '1', 'title' => 'Layout', '--brief' => 'Fix the layout.', '--deliverables' => $path, '--json' => true]))->toBe(0)
            ->and(Artisan::call('task:update', ['task' => '2', '--deliverables' => $path, '--json' => true]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskRequest
            && str_contains((string) $request->body(), '"fails_on_base":'.$encoded)
            && str_contains((string) $request->body(), '"paths":["apps\\/gateway\\/tests\\/Feature\\/HomeScreenTest.php"]'));
        $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateTaskRequest
            && str_contains((string) $request->body(), '"fails_on_base":'.$encoded));
    })->with([
        'true' => [true],
        'false' => [false],
    ]);

    it('refuses fails_on_base on a deliverable that is not a command', function (string $type, array $extra): void {
        $path = $this->orbitHome.'/deliverables.json';
        is_dir($this->orbitHome) || mkdir($this->orbitHome, 0700, true);
        file_put_contents($path, json_encode([[
            'id' => 'docs',
            'type' => $type,
            'description' => 'Docs',
            'fails_on_base' => true,
            ...$extra,
        ]], JSON_THROW_ON_ERROR));
        $mock = MockClient::global([]);

        expect(Artisan::call('task:update', ['task' => '2', '--deliverables' => $path, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['message'])->toBe('The fails_on_base field is only allowed on a command deliverable (deliverable docs).');
        $mock->assertNothingSent();

        MockClient::destroyGlobal();
        $mock = MockClient::global([]);

        expect(Artisan::call('task:create', ['--parent' => '1', 'title' => 'Docs', '--brief' => 'Write the docs.', '--deliverables' => $path, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('task.deliverables_invalid');
        $mock->assertNothingSent();
    })->with([
        'a file' => ['file', ['path' => 'docs/a.md', 'change' => 'any']],
        'a review' => ['review', []],
    ]);

    it('refuses paths that are not a list of strings', function (): void {
        $path = $this->orbitHome.'/deliverables.json';
        is_dir($this->orbitHome) || mkdir($this->orbitHome, 0700, true);
        file_put_contents($path, json_encode([[
            'id' => 'repro',
            'type' => 'command',
            'description' => 'Repro',
            'command' => 'bun test',
            'paths' => ['a.ts', 3],
        ]], JSON_THROW_ON_ERROR));
        $mock = MockClient::global([]);

        expect(Artisan::call('task:update', ['task' => '2', '--deliverables' => $path, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['message'])->toBe('The paths value for deliverable repro must be a list of strings.');
        $mock->assertNothingSent();
    });

    it('passes the agent thread cursor through to the Gateway', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-agent-show/delta'));

        expect(Artisan::call('task:agent:show', ['task' => '1', 'session' => '3', '--after-sequence' => 'run-1.7', '--json' => true]))->toBe(0)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['kind' => 'delta', 'cursor' => 'run-1.9']);
        $mock->assertSent(static fn (Request $request): bool => $request instanceof ShowTaskAgentRequest
            && $request->query()->all() === ['after_sequence' => 'run-1.7']);
    });

    it('shows the watched pull request in JSON without changing the publication URL', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-show/watched'));

        expect(Artisan::call('task:show', ['task' => '1', '--json' => true]))->toBe(0);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($output['watched_pr_url'])->toBe('https://github.com/hardimpactdev/orbit/pull/451')
            ->and($output['watched_pr_number'])->toBe(451)
            ->and($output['watched_pr_state'])->toBe('open')
            ->and($output['pr_url'])->toBeNull();
        $mock->assertSent(ShowTaskRequest::class);
    });

    it('shows fails_on_base for a test deliverable', function (): void {
        $fixture = json_decode((string) file_get_contents(gateway_fixture_path('tasks/task-show/default')), true, flags: JSON_THROW_ON_ERROR);
        $fixture['body']['data']['subtasks'][0]['deliverables'][1]['fails_on_base'] = true;
        MockClient::global([
            ShowTaskRequest::class => MockResponse::make($fixture['body'], $fixture['status']),
        ]);

        expect(Artisan::call('task:show', ['task' => '1']))->toBe(0);
        $human = Artisan::output();
        expect($human)->toContain('Fails on the start commit')->toContain('sdk-test');

        MockClient::destroyGlobal();
        MockClient::global([
            ShowTaskRequest::class => MockResponse::make($fixture['body'], $fixture['status']),
        ]);

        expect(Artisan::call('task:show', ['task' => '1', '--json' => true]))->toBe(0);
        expect(Artisan::output())->toContain('"fails_on_base":true');
    });

    it('returns the Gateway error code when a subtask cannot be cancelled', function (string $fixture, string $code): void {
        MockClient::global(gateway_fixture_mock($fixture));

        expect(Artisan::call('task:cancel', ['task' => '1', '--yes' => true, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($code);
    })->with([
        'not running' => ['tasks/task-cancel/child-not-running', 'tasks.subtask_not_running'],
        'interrupt failed' => ['tasks/task-cancel/child-interrupt-failed', 'tasks.subtask_interrupt_failed'],
    ]);
});

describe('prompts', function (): void {
    it('selects a group by its stable ID from the offered statuses', function (): void {
        $mock = task_prompt_mock();
        [$status, $display] = task_prompt_run('select-backlog', [Key::DOWN, Key::ENTER]);

        expect($status)->toBe(0)
            ->and($display)->toContain('selected 3')
            ->not->toContain('Settling feature');
        $mock->assertSent(static fn (Request $request): bool => $request instanceof ListTasksRequest
            && $request->query()->all() === ['status' => 'backlog']);
    });

    it('leaves out excluded statuses and stops on an empty list', function (): void {
        task_prompt_mock();
        [$status, $display] = task_prompt_run('select-settling', []);

        expect($status)->toBe(1)
            ->and($display)->toContain('No matching records were found.');
    });

    it('selects a Project and asks for a title again until it is valid', function (): void {
        $mock = task_prompt_mock();
        [$status, $display] = task_prompt_run('create-inputs', [
            Key::DOWN, Key::ENTER,
            Key::ENTER, 'F', 'e', 'a', 't', Key::ENTER,
            'B', 'r', 'i', 'e', 'f', Key::CTRL_D,
        ]);

        expect($status)->toBe(0)
            ->and($display)->toContain('Title is required.')
            ->and($display)->toContain('project 42 · Feat · Brief');
        $mock->assertSent(ListProjectsRequest::class);
    });

    it('asks which fields change', function (): void {
        task_prompt_mock();
        [$status, $display] = task_prompt_run('fields', [Key::DOWN, Key::DOWN, Key::SPACE, Key::ENTER]);

        expect($status)->toBe(0)->and($display)->toContain('fields status');
    });

    it('defaults consent to No and accepts an explicit yes', function (array $keys, int $expected, string $text): void {
        task_prompt_mock();
        [$status, $display] = task_prompt_run('consent', $keys);

        expect($status)->toBe($expected)
            ->and($display)->toContain('Cancel task group ORB-1 (Feature)?')
            ->and($display)->toContain($text);
    })->with([
        'default' => [[Key::ENTER], 1, 'was not cancelled'],
        'yes' => [['y', Key::ENTER], 0, 'consented'],
    ]);

    it('offers only agent threads that have a transcript', function (): void {
        $mock = task_prompt_mock();
        [$status, $display] = task_prompt_run('select-agent', [Key::ENTER]);

        expect($status)->toBe(0)
            ->and($display)->toContain('selected 4')
            ->not->toContain('stored-t3-thread')->not->toContain('t3 ');
        $mock->assertSent(ListTaskAgentsRequest::class);
    });

    it('stops before mutation when a selection is cancelled', function (): void {
        $mock = task_prompt_mock();
        [$status] = task_prompt_run('select-backlog', [Key::CTRL_C]);

        expect($status)->not->toBe(0);
        $mock->assertNotSent(CancelTaskRequest::class);
    });
});

function task_prompt_mock(): MockClient
{
    $meta = ['request_id' => '11111111-1111-4111-8111-111111111111'];
    $group = static fn (int $id, string $title, string $status): array => [
        'id' => $id, 'project_id' => 1, 'project' => 'orbit', 'project_code' => 'ORB', 'title' => $title, 'brief' => 'Brief', 'orchestration' => 'external', 'status' => $status, 'subtasks' => [],
    ];

    return MockClient::global([
        ListTasksRequest::class => static fn ($pending): MockResponse => MockResponse::make(['data' => array_values(array_filter(
            [$group(1, 'Backlog feature', 'backlog'), $group(3, 'Second backlog feature', 'backlog'), $group(5, 'Settling feature', 'settling')],
            static fn (array $row): bool => ! isset($pending->getRequest()->query()->all()['status']) || $row['status'] === $pending->getRequest()->query()->all()['status'],
        )), 'meta' => $meta]),
        ShowTaskRequest::class => MockResponse::make(['data' => $group(1, 'Feature', 'backlog'), 'meta' => $meta]),
        ListTaskAgentsRequest::class => MockResponse::make(['data' => [
            ['id' => 2, 'task_group_id' => 1, 'task_id' => null, 'role' => 'reviewer', 'driver' => 't3', 'external_id' => 'stored-t3-thread', 'state' => 'done'],
            ['id' => 4, 'task_group_id' => 1, 'task_id' => 7, 'role' => 'implementer', 'driver' => 'pi', 'external_id' => 'session-4', 'state' => 'working'],
        ], 'meta' => $meta]),
        ListProjectsRequest::class => MockResponse::make(['data' => [
            ['id' => 7, 'name' => 'Alpha', 'slug' => 'alpha', 'type' => 'monorepo', 'repository_url' => 'https://example.test/alpha.git'],
            ['id' => 42, 'name' => 'Beta', 'slug' => 'beta', 'type' => 'monorepo', 'repository_url' => 'https://example.test/beta.git'],
        ], 'meta' => $meta]),
    ]);
}

/**
 * @param  list<string>  $keys
 * @return array{int, string}
 */
function task_prompt_run(string $scenario, array $keys): array
{
    $command = new TaskPromptsCommand(new TaskPromptsTerminal($keys));
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $status = $tester->execute(['--scenario' => $scenario]);

    return [$status, $tester->getDisplay()];
}

/** Drives the shared tasks prompt helpers through a scripted terminal. */
final class TaskPromptsCommand extends TaskCommand
{
    use ConfirmsTaskChanges;

    #[Override]
    protected $signature = 'test:task-prompts {--scenario=} {--yes} {--json}';

    public function __construct(private readonly Terminal $terminal)
    {
        parent::__construct();
    }

    protected function consoleMode(?OutputInterface $output = null): ConsoleMode
    {
        return new ConsoleMode(false, true, false, false, 100);
    }

    protected function commandPrompts(): CommandPrompts
    {
        return new CommandPrompts($this->consoleMode(), $this->output, $this->terminal);
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        return PromptContext::preserve(function () use ($repository, $factory): int {
            Prompt::fallbackWhen(false);
            $connector = $this->gatewayConnector($repository, $factory);
            assert($connector !== null);

            switch ($this->option('scenario')) {
                case 'select-backlog':
                    $id = $this->selectGroup($connector, ['backlog']);
                    $this->line("selected {$id}");

                    return self::SUCCESS;
                case 'select-agent':
                    $id = $this->selectAgent($connector, 1);
                    $this->line("selected {$id}");

                    return self::SUCCESS;
                case 'select-settling':
                    $id = $this->selectGroup($connector, excluded: ['backlog', 'settling']);
                    $this->line("selected {$id}");

                    return self::SUCCESS;
                case 'create-inputs':
                    $project = $this->selectProject($connector);
                    $title = $this->promptText('Title', self::TITLE_MAX);
                    $brief = $this->promptText('Brief', self::BRIEF_MAX, multiline: true);
                    $this->line("project {$project} · {$title} · {$brief}");

                    return self::SUCCESS;
                case 'fields':
                    $this->line('fields '.implode(',', $this->promptFields(['title' => 'Title', 'brief' => 'Brief', 'status' => 'Status'])));

                    return self::SUCCESS;
                default:
                    if (! $this->consent(fn (): string => 'Cancel task group '.$this->loadGroup($connector, 1)?->reference().' (Feature)?', 'Task group [1] was not cancelled.')) {
                        return self::FAILURE;
                    }

                    $this->line('consented');

                    return self::SUCCESS;
            }
        });
    }
}

describe('assistance columns', function (): void {
    it('shows direction requests before failures, and the kind on the list and the group', function (): void {
        $columns = getenv('COLUMNS');
        putenv('COLUMNS=160');
        $recorded = json_decode((string) file_get_contents(dirname(__DIR__, 5).'/packages/php-sdk/fixtures/tasks/task-show/default.json'), true, flags: JSON_THROW_ON_ERROR);
        $group = $recorded['body']['data'];
        $group['assistance_requested'] = true;
        $group['assistance_kind'] = 'direction';
        $group['assistance_question'] = 'Which database should this use?';
        $group['assistance_reason'] = 'The implementer is blocked.';
        $group['subtasks'][0]['assistance_requested'] = true;
        $group['subtasks'][0]['assistance_kind'] = 'direction';
        $group['subtasks'][0]['assistance_question'] = 'Which database should this use?';
        $group['subtasks'][0]['assistance_reason'] = 'The implementer is blocked.';
        $failure = $group;
        $failure['id'] = 3;
        $failure['title'] = 'Failed push';
        $failure['assistance_kind'] = 'failure';
        $failure['assistance_question'] = null;
        $failure['assistance_reason'] = 'The push failed.';
        $failure['subtasks'][0]['assistance_kind'] = 'failure';
        $failure['subtasks'][0]['assistance_question'] = null;
        $failure['subtasks'][0]['assistance_reason'] = 'The push failed.';
        $clear = $group;
        $clear['id'] = 2;
        $clear['title'] = 'Clear';
        $clear['assistance_requested'] = false;
        $clear['assistance_kind'] = 'direction';
        $clear['assistance_question'] = 'An old question.';
        $clear['assistance_reason'] = 'An old reason.';
        $clear['subtasks'][0]['assistance_requested'] = false;
        $clear['subtasks'][0]['assistance_kind'] = 'direction';
        $clear['subtasks'][0]['assistance_question'] = 'An old question.';
        $clear['subtasks'][0]['assistance_reason'] = 'An old reason.';
        $body = static fn (array $data): array => ['data' => $data, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];

        MockClient::global([
            ListTasksRequest::class => MockResponse::make($body([$failure, $group, $clear])),
        ]);

        expect(Artisan::call('task:list'))->toBe(0);
        $list = Artisan::output();
        expect($list)->toContain('KIND')
            ->and($list)->toContain('direction')
            ->and($list)->toContain('failure')
            ->and($list)->toContain('Which database should this use?')
            ->and($list)->toContain('The push failed.')
            ->and($list)->not->toContain('An old reason.')
            ->and($list)->not->toContain('An old question.');

        MockClient::destroyGlobal();
        MockClient::global([
            ShowTaskRequest::class => MockResponse::make($body($group)),
        ]);

        expect(Artisan::call('task:show', ['task' => '1']))->toBe(0);
        $shown = Artisan::output();
        expect($shown)->toContain('Kind')
            ->and($shown)->toContain('direction')
            ->and($shown)->toContain('Which database should this use?')
            ->and($shown)->toContain('The implementer is blocked.')
            ->and($shown)->toContain('KIND');

        MockClient::destroyGlobal();
        MockClient::global([
            ShowTasksStatusRequest::class => MockResponse::make($body([
                'enabled' => true,
                'assistance' => [
                    [
                        'id' => 2,
                        'project_id' => 1,
                        'project' => 'orbit',
                        'project_code' => 'ORB',
                        'title' => 'Failed push',
                        'status' => 'running',
                        'assistance_kind' => 'failure',
                        'assistance_question' => null,
                        'assistance_reason' => 'The push failed.',
                    ],
                    [
                        'id' => 4,
                        'project_id' => 1,
                        'project' => 'orbit',
                        'project_code' => 'ORB',
                        'title' => 'Needs a database',
                        'status' => 'running',
                        'assistance_kind' => 'direction',
                        'assistance_question' => 'Which database should this use?',
                        'assistance_reason' => 'The implementer is blocked.',
                    ],
                    [
                        'id' => 9,
                        'project_id' => 1,
                        'project' => 'orbit',
                        'project_code' => 'ORB',
                        'title' => 'Needs an ADR',
                        'status' => 'reviewing',
                        'assistance_kind' => 'direction',
                        'assistance_question' => 'Which ADR applies?',
                        'assistance_reason' => 'The reviewer is blocked.',
                    ],
                    [
                        'id' => 11,
                        'project_id' => 1,
                        'project' => 'orbit',
                        'project_code' => 'ORB',
                        'title' => 'Check failed',
                        'status' => 'settling',
                        'assistance_kind' => 'failure',
                        'assistance_question' => null,
                        'assistance_reason' => 'The check failed.',
                    ],
                ],
            ])),
        ]);

        expect(Artisan::call('task:status'))->toBe(0);
        $status = Artisan::output();
        $directionAt = strpos($status, 'Needs your direction');
        $failuresAt = strpos($status, 'Failures');
        expect($directionAt)->not->toBeFalse()
            ->and($failuresAt)->not->toBeFalse()
            ->and($directionAt)->toBeLessThan($failuresAt)
            ->and(strpos($status, 'Which database should this use?'))->toBeLessThan(strpos($status, 'Which ADR applies?'))
            ->and(strpos($status, 'Which ADR applies?'))->toBeLessThan(strpos($status, 'The push failed.'))
            ->and(strpos($status, 'The push failed.'))->toBeLessThan(strpos($status, 'The check failed.'))
            ->and($status)->not->toContain('An old reason.');

        expect(Artisan::call('task:status', ['--json' => true]))->toBe(0);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($json['assistance'], 'id'))->toBe([2, 4, 9, 11])
            ->and($json['assistance'][1])->toMatchArray([
                'id' => 4,
                'project_code' => 'ORB',
                'assistance_kind' => 'direction',
                'assistance_question' => 'Which database should this use?',
                'assistance_reason' => 'The implementer is blocked.',
            ]);
        putenv($columns === false ? 'COLUMNS' : 'COLUMNS='.$columns);
    });
});

final class TaskPromptsTerminal extends Terminal
{
    /** @param list<string> $keys */
    public function __construct(private array $keys)
    {
        parent::__construct();
    }

    public function read(): string
    {
        return array_shift($this->keys) ?? throw new PromptAborted('Input ended.');
    }

    public function setTty(string $mode): void {}

    public function restoreTty(): void {}

    public function cols(): int
    {
        return 100;
    }

    public function lines(): int
    {
        return 40;
    }
}

it('shows observed power and capacity wait in human and JSON output', function (bool $json, ?string $power): void {
    $fixture = json_decode((string) file_get_contents(gateway_fixture_path('tasks/task-show/default')), true, flags: JSON_THROW_ON_ERROR);
    $fixture['body']['data']['vm_power'] = $power;
    $fixture['body']['data']['capacity_wait_reason'] = 'The local VM budget is full.';
    MockClient::global([ShowTaskRequest::class => MockResponse::make($fixture['body'])]);

    expect(Artisan::call('task:show', ['task' => '1', '--json' => $json, '--no-interaction' => true]))->toBe(0);
    $output = Artisan::output();
    if ($json) {
        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['vm_power'] ?? null)->toBe($power)
            ->and($data['capacity_wait_reason'])->toBe('The local VM budget is full.');
        if ($power === null) {
            expect($data)->not->toHaveKey('vm_power');
        }
    } else {
        expect($output)->toContain('Waiting for capacity', 'The local VM budget is full.');
        if ($power !== null) {
            expect($output)->toContain('VM power', $power);
        } else {
            expect($output)->not->toContain('VM power');
        }
    }
})->with([false, true])->with(['running', 'stopped', 'destroyed', null]);

it('sends explicit preview updates without changing review status', function (bool $preview): void {
    $fixture = json_decode((string) file_get_contents(gateway_fixture_path('tasks/task-show/default')), true, flags: JSON_THROW_ON_ERROR);
    $fixture['body']['data']['preview'] = $preview;
    $fixture['body']['data']['status'] = 'waiting_for_review';
    $mock = MockClient::global([UpdateTaskRequest::class => MockResponse::make($fixture['body'])]);

    expect(Artisan::call('task:update', ['task' => '1', $preview ? '--preview' : '--no-preview' => true, '--json' => true]))->toBe(0);
    expect(json_decode($mock->getLastRequest()?->body()->all(), true, flags: JSON_THROW_ON_ERROR))->toBe(['preview' => $preview]);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['preview'])->toBe($preview);
})->with([true, false]);

it('refuses conflicting preview flags before a Gateway request', function (): void {
    $mock = MockClient::global([]);
    expect(Artisan::call('task:update', ['task' => '1', '--preview' => true, '--no-preview' => true, '--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('task.preview_conflict');
    $mock->assertNothingSent();
});

it('requests a preview on task group creation', function (): void {
    $mock = MockClient::global(gateway_fixture_mock('tasks/task-create/created'));
    expect(Artisan::call('task:create', ['title' => 'Preview', '--project' => '1', '--brief' => 'Keep it running.', '--preview' => true, '--json' => true]))->toBe(0);
    expect(json_decode($mock->getLastRequest()?->body()->all(), true, flags: JSON_THROW_ON_ERROR)['preview'])->toBeTrue();
});

it('declares the current recipe on task group creation only when asked', function (bool $current): void {
    $mock = MockClient::global(gateway_fixture_mock('tasks/task-create/created'));
    expect(Artisan::call('task:create', ['title' => 'Recipe', '--project' => '1', '--brief' => 'Needs the new image.', '--json' => true, ...($current ? ['--current-recipe' => true] : [])]))->toBe(0);
    $body = json_decode($mock->getLastRequest()?->body()->all(), true, flags: JSON_THROW_ON_ERROR);
    expect($current ? $body['current_recipe'] : array_key_exists('current_recipe', $body))->toBe($current);
})->with([true, false]);

describe('declared topology', function (): void {
    it('sends a declaration and an explicit empty update', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/task-create/child-created'));
        expect(Artisan::call('task:create', ['--parent' => '13', 'title' => 'Step', '--brief' => 'Work', '--topology' => '["app-dev","app-prod+ingress"]', '--json' => true]))->toBe(0);
        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskRequest
            && json_decode((string) $request->body(), true)['topology'] === ['app-dev', 'app-prod+ingress']);
        MockClient::destroyGlobal();
        $fixture = json_decode((string) file_get_contents(base_path('../../packages/php-sdk/fixtures/tasks/task-create/child-created.json')), true, flags: JSON_THROW_ON_ERROR);
        $mock = MockClient::global([UpdateTaskRequest::class => MockResponse::make($fixture['body'])]);
        expect(Artisan::call('task:update', ['task' => '57', '--topology' => '[]', '--json' => true]))->toBe(0);
        $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateTaskRequest
            && json_decode((string) $request->body(), true) === ['topology' => []]);
    });

    it('rejects invalid declarations before sending a request', function (string $value): void {
        $mock = MockClient::global([]);
        expect(Artisan::call('task:create', ['--parent' => '13', 'title' => 'Step', '--brief' => 'Work', '--topology' => $value, '--json' => true]))->toBe(1);
        expect(json_decode(Artisan::output(), true)['error']['code'])->toBe('task.invalid_topology');
        $mock->assertNothingSent();
    })->with(['[""]', '["app-dev","app-dev"]', '[1]', '{}', 'null', 'app-dev']);
});

it('sends the requested orchestration mode when creating a group', function (string $mode): void {
    $mock = MockClient::global(gateway_fixture_mock('tasks/task-create/created'));
    expect(Artisan::call('task:create', ['title' => 'Work', '--project' => '1', '--brief' => 'Brief', '--orchestration' => $mode, '--json' => true]))->toBe(0);
    $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskRequest
        && json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR)['orchestration'] === $mode);
})->with(['external']);

it('refuses an unknown orchestration mode before contacting the Gateway', function (): void {
    $mock = MockClient::global([]);
    expect(Artisan::call('task:create', ['title' => 'Work', '--project' => '1', '--brief' => 'Brief', '--orchestration' => 'other', '--json' => true]))->toBe(1);
    expect(Artisan::output())->toContain('task.orchestration_invalid');
    $mock->assertNothingSent();
});

it('transports external board status updates without deciding lifecycle policy', function (): void {
    $mock = MockClient::global([
        ...gateway_fixture_mock('tasks/task-update/updated'),
        ...gateway_fixture_mock('tasks/task-update/child-updated'),
    ]);

    expect(Artisan::call('task:update', ['task' => '13', '--status' => 'reviewing', '--json' => true]))->toBe(0);
    expect(Artisan::call('task:update', ['task' => '57', '--status' => 'completed', '--json' => true]))->toBe(0);

    $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateTaskRequest
        && (string) $request->body() === '{"status":"reviewing"}');
    $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateTaskRequest
        && (string) $request->body() === '{"status":"completed"}');
});

it('registers one task family with direct selectors and no old aliases', function (): void {
    $commands = app(\Illuminate\Contracts\Console\Kernel::class)->all();
    $names = array_keys(array_filter($commands, static fn ($command): bool => str_starts_with($command->getName() ?? '', 'task:')));
    sort($names);
    expect($names)->toBe([
        'task:agent:list', 'task:agent:show', 'task:cancel', 'task:check', 'task:comment:create', 'task:comment:list',
        'task:complete', 'task:create', 'task:destroy', 'task:egress', 'task:list', 'task:park', 'task:publish',
        'task:question:close', 'task:question:list', 'task:show', 'task:start', 'task:status', 'task:turn', 'task:update',
    ])->and(array_filter(array_keys($commands), static fn (string $name): bool => str_starts_with($name, 'tasks:')))->toBe([]);
    foreach (['task:check', 'task:turn', 'task:cancel', 'task:destroy', 'task:show', 'task:update', 'task:comment:create', 'task:comment:list'] as $name) {
        expect(array_keys($commands[$name]->getDefinition()->getArguments()))->toBe(['task'])
            ->and($commands[$name]->getDefinition()->hasOption('subtask'))->toBeFalse();
    }
});
