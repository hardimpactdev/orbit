<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Tasks\CancelTaskRequest;
use Orbit\Sdk\Requests\Tasks\CheckTaskRequest;
use Orbit\Sdk\Requests\Tasks\CloseTaskQuestionRequest;
use Orbit\Sdk\Requests\Tasks\CompleteTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskCommentRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskRequest;
use Orbit\Sdk\Requests\Tasks\DestroyTaskRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskAgentsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskCommentsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskEgressRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskQuestionsRequest;
use Orbit\Sdk\Requests\Tasks\ListTasksRequest;
use Orbit\Sdk\Requests\Tasks\PublishTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskAgentRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskRequest;
use Orbit\Sdk\Requests\Tasks\ShowTasksStatusRequest;
use Orbit\Sdk\Requests\Tasks\StartTaskRequest;
use Orbit\Sdk\Requests\Tasks\SubtaskInput;
use Orbit\Sdk\Requests\Tasks\TurnTaskRequest;
use Orbit\Sdk\Requests\Tasks\UpdateTaskRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskAgentSnapshotResponse;
use Orbit\Sdk\Responses\Tasks\TaskAgentsResponse;
use Orbit\Sdk\Responses\Tasks\TaskAssistanceResponse;
use Orbit\Sdk\Responses\Tasks\TaskCancellationResponse;
use Orbit\Sdk\Responses\Tasks\TaskCommentResponse;
use Orbit\Sdk\Responses\Tasks\TaskCommentsResponse;
use Orbit\Sdk\Responses\Tasks\TaskEgressResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupsResponse;
use Orbit\Sdk\Responses\Tasks\TaskQuestionResponse;
use Orbit\Sdk\Responses\Tasks\TaskQuestionsResponse;
use Orbit\Sdk\Responses\Tasks\TasksStatusResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('task transport', function (): void {
    it('addresses every tasks route', function (GatewayRequest $request, Method $method, string $endpoint): void {
        expect($request->getMethod())->toBe($method)
            ->and($request->resolveEndpoint())->toBe($endpoint);
    })->with([
        'status' => [new ShowTasksStatusRequest, Method::GET, '/api/v1/tasks/status'],
        'list' => [new ListTasksRequest, Method::GET, '/api/v1/tasks'],
        'create' => [new CreateTaskRequest(1, 'Title', 'Brief'), Method::POST, '/api/v1/tasks'],
        'show' => [new ShowTaskRequest(13), Method::GET, '/api/v1/tasks/13'],
        'update' => [new UpdateTaskRequest(13), Method::PATCH, '/api/v1/tasks/13'],
        'publish' => [new PublishTaskGroupRequest(13, 17), Method::POST, '/api/v1/tasks/13/publish'],
        'cancel' => [new CancelTaskRequest(13, 'cancel.13'), Method::POST, '/api/v1/tasks/13/cancel'],
        'check' => [new CheckTaskRequest(21, 'baseline'), Method::POST, '/api/v1/tasks/21/check'],
        'turn' => [new TurnTaskRequest(14, 'implementer', 'Prompt', 'new'), Method::POST, '/api/v1/tasks/14/turn'],
        'provision' => [new StartTaskRequest(13), Method::POST, '/api/v1/tasks/13/start'],
        'complete' => [new CompleteTaskGroupRequest(13), Method::POST, '/api/v1/tasks/13/complete'],
        'subtask create' => [new CreateTaskRequest(projectId: null, title: 'Title', brief: 'Brief', parentId: 13), Method::POST, '/api/v1/tasks'],
        'subtask update' => [new UpdateTaskRequest(57), Method::PATCH, '/api/v1/tasks/57'],
        'subtask destroy' => [new DestroyTaskRequest(57), Method::DELETE, '/api/v1/tasks/57'],
        'subtask cancel' => [new CancelTaskRequest(57, 'cancel.child'), Method::POST, '/api/v1/tasks/57/cancel'],
        'group comment create' => [new CreateTaskCommentRequest(13, 'resolution', 'Done.', 'nick'), Method::POST, '/api/v1/tasks/13/comments'],
        'group comment list' => [new ListTaskCommentsRequest(13), Method::GET, '/api/v1/tasks/13/comments'],
        'comment create' => [new CreateTaskCommentRequest(57, 'resolution', 'Done.', 'nick'), Method::POST, '/api/v1/tasks/57/comments'],
        'comment list' => [new ListTaskCommentsRequest(57), Method::GET, '/api/v1/tasks/57/comments'],
        'question list' => [new ListTaskQuestionsRequest, Method::GET, '/api/v1/task-questions'],
        'question close' => [new CloseTaskQuestionRequest(98, 'superseded', 'Stale.'), Method::POST, '/api/v1/task-questions/98/close'],
        'agents' => [new ListTaskAgentsRequest(13), Method::GET, '/api/v1/tasks/13/agents'],
        'egress' => [new ListTaskEgressRequest(13), Method::GET, '/api/v1/tasks/13/egress'],
        'agent show' => [new ShowTaskAgentRequest(13, 21), Method::GET, '/api/v1/tasks/13/agents/21'],
    ]);

    it('transports only group assistance fields and preserves every supplied filter', function (): void {
        expect(new CreateTaskCommentRequest(13, 'resolution', '', '')->body()->all())
            ->toBe('{"type":"resolution","body":"","author":""}')
            ->and(new CreateTaskCommentRequest(13, 'resolution', 'Done.', 'nick')->headers()->get('Content-Type'))->toBe('application/json')
            ->and(new ListTaskCommentsRequest(13)->query()->all())->toBe([])
            ->and(new ListTaskCommentsRequest(13, 'resolution', 1)->query()->all())->toBe(['type' => 'resolution', 'limit' => 1])
            ->and(new ListTaskCommentsRequest(13, '', 0)->query()->all())->toBe(['type' => '', 'limit' => 0]);
    });

    it('sends the agent thread cursor only when one is given', function (): void {
        expect(new ShowTaskAgentRequest(13, 21)->query()->all())->toBe([])
            ->and(new ShowTaskAgentRequest(13, 21, 'run-1.7')->query()->all())->toBe(['after_sequence' => 'run-1.7']);
    });

    it('sends create fields and omits only absent optional values', function (): void {
        expect(new CreateTaskRequest(1, 'Title', 'Brief')->body()->all())
            ->toBe('{"project_id":1,"title":"Title","brief":"Brief"}')
            ->and(new CreateTaskRequest(1, 'Title', 'Brief', 'todo', subtasks: [new SubtaskInput('One', 'First.')])->body()->all())
            ->toBe('{"project_id":1,"title":"Title","brief":"Brief","status":"todo","subtasks":[{"title":"One","brief":"First."}]}')
            ->and(new CreateTaskRequest(1, 'Title', 'Brief', currentRecipe: true)->body()->all())
            ->toBe('{"project_id":1,"title":"Title","brief":"Brief","current_recipe":true}')
            ->and(new CreateTaskRequest(1, 'Title', 'Brief', subtasks: [])->body()->all())
            ->toBe('{"project_id":1,"title":"Title","brief":"Brief","subtasks":[]}')
            ->and(new CreateTaskRequest(1, 'Title', 'Brief')->headers()->get('Content-Type'))
            ->toBe('application/json');
    });

    it('sends only the supplied update fields and an empty object when none is supplied', function (): void {
        expect(new UpdateTaskRequest(13)->body()->all())->toBe('{}')
            ->and(new UpdateTaskRequest(13, status: 'todo')->body()->all())->toBe('{"status":"todo"}')
            ->and(new UpdateTaskRequest(13, '', 'Brief')->body()->all())->toBe('{"title":"","brief":"Brief"}')
            ->and(new UpdateTaskRequest(57)->body()->all())->toBe('{}')
            ->and(new UpdateTaskRequest(57, position: 2)->body()->all())->toBe('{"position":2}')
            ->and(new UpdateTaskRequest(57, deliverables: [['id' => 'done', 'type' => 'review', 'description' => 'Done.']])->body()->all())
            ->toBe('{"deliverables":[{"id":"done","type":"review","description":"Done."}]}');
    });

    it('sends subtask and comment bodies', function (): void {
        $docs = ['id' => 'docs', 'type' => 'file', 'description' => 'Docs', 'path' => 'docs/a.md', 'change' => 'any'];
        $repro = ['id' => 'layout-repro', 'type' => 'test', 'description' => 'Fails before the fix', 'project' => 'apps/gateway', 'file' => 'tests/Feature/HomeScreenTest.php', 'name' => 'home screen layout', 'fails_on_base' => true];

        expect(new CreateTaskRequest(projectId: null, title: 'Title', brief: 'Brief', parentId: 13)->body()->all())
            ->toBe('{"parent_id":13,"title":"Title","brief":"Brief"}')
            ->and(new CreateTaskRequest(projectId: null, title: 'Title', brief: 'Brief', parentId: 13, deliverables: [$docs])->body()->all())
            ->toBe('{"parent_id":13,"title":"Title","brief":"Brief","deliverables":[{"id":"docs","type":"file","description":"Docs","path":"docs\\/a.md","change":"any"}]}')
            ->and(new CreateTaskRequest(projectId: null, title: 'Title', brief: 'Brief', parentId: 13, deliverables: [$repro])->body()->all())
            ->toContain('"fails_on_base":true')
            ->and(new UpdateTaskRequest(57, deliverables: [$repro])->body()->all())
            ->toContain('"fails_on_base":true')
            ->and(new SubtaskInput('One', 'First.', [$docs])->toArray())
            ->toBe(['title' => 'One', 'brief' => 'First.', 'deliverables' => [$docs]])
            ->and(new CreateTaskCommentRequest(57, 'resolution', 'Done.', 'nick')->body()->all())
            ->toBe('{"type":"resolution","body":"Done.","author":"nick"}')
            ->and(new CreateTaskCommentRequest(57, 'assistance_requested', 'Stuck.', 'nick', 4)->body()->all())
            ->toBe('{"type":"assistance_requested","body":"Stuck.","author":"nick","agent_thread_id":4}');
    });

    it('puts list filters in the query and omits absent ones', function (): void {
        expect(new ListTasksRequest()->query()->all())->toBe([])
            ->and(new ListTasksRequest(4, 'backlog')->query()->all())->toBe(['project_id' => 4, 'status' => 'backlog'])
            ->and(new ListTaskQuestionsRequest()->query()->all())->toBe([])
            ->and(new ListTaskQuestionsRequest(4, 'contract_gap', 'answered', '2026-10-07T00:00:00Z')->query()->all())
            ->toBe(['project_id' => 4, 'cause' => 'contract_gap', 'status' => 'answered', 'since' => '2026-10-07T00:00:00Z']);
    });

    it('keeps status, task, and read requests bodyless', function (GatewayRequest $request): void {
        $mockClient = new MockClient([MockResponse::make(['data' => ['enabled' => true, 'id' => 1], 'meta' => ['request_id' => task_request_id()]])]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send($request);
        } catch (GatewayApiException) {
            // Only the outgoing request matters here.
        }

        expect($request)->not->toBeInstanceOf(HasBody::class)
            ->and((string) $mockClient->getLastPendingRequest()?->createPsrRequest()->getBody())->toBeEmpty();
    })->with([
        'group comment list' => [new ListTaskCommentsRequest(13)],
        'status' => [new ShowTasksStatusRequest],
        'question list' => [new ListTaskQuestionsRequest],
        'complete' => [new CompleteTaskGroupRequest(13)],
        'destroy' => [new DestroyTaskRequest(57)],
        'agent show' => [new ShowTaskAgentRequest(13, 21)],
    ]);

    it('sends a cancel as a JSON object that carries discard only when asked', function (CancelTaskRequest $request, string $body): void {
        $mockClient = new MockClient([MockResponse::make(['data' => ['id' => 1], 'meta' => ['request_id' => task_request_id()]], 202)]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send($request);
        } catch (GatewayApiException) {
            // Only the outgoing request matters here.
        }

        $sent = $mockClient->getLastPendingRequest()?->createPsrRequest();
        expect((string) $sent?->getBody())->toBe($body)
            ->and($sent?->getHeaderLine('Content-Type'))->toBe('application/json');
    })->with([
        'cancel' => [new CancelTaskRequest(13, 'cancel.13'), '{}'],
        'subtask cancel' => [new CancelTaskRequest(57, 'cancel.child'), '{}'],
        'cancel with discard' => [new CancelTaskRequest(13, 'cancel.13', discard: true), '{"discard":true}'],
    ]);
});

describe('task responses from recorded Gateway fixtures', function (): void {
    it('keeps group ownership and the ordinary conflict envelope', function (): void {
        $comment = task_fixture_send('task-comment-create/parent-requested', new CreateTaskCommentRequest(1, 'assistance_requested', 'May I republish?', 'driver'));
        expect($comment)->toBeInstanceOf(TaskCommentResponse::class)
            ->and($comment->taskId)->toBe(1)->and($comment->taskGroupId)->toBe(1)->and($comment->agentThreadId)->toBeNull();
        try {
            task_fixture_send('task-comment-create/parent-subtask-open', new CreateTaskCommentRequest(1, 'resolution', 'Approved.', 'nick'));
            test()->fail('Expected a group admission refusal.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe('tasks.assistance_subtask_open');
        }
    });

    it('maps each recorded success to its typed DTO', function (string $fixture, GatewayRequest $request, string $class): void {
        $response = task_fixture_send($fixture, $request);
        if ($response instanceof TaskCancellationResponse) {
            $response = $response->result;
        }

        expect($response)->toBeInstanceOf($class)
            ->and($response->requestId)->toBe(task_request_id());
    })->with([
        'status' => ['task-status/enabled', new ShowTasksStatusRequest, TasksStatusResponse::class],
        'assisted status' => ['task-status/assistance', new ShowTasksStatusRequest, TasksStatusResponse::class],
        'review-and-merge status' => ['task-status/merges', new ShowTasksStatusRequest, TasksStatusResponse::class],
        'review-and-merge show' => ['task-show/review-and-merge', new ShowTaskRequest(1), TaskGroupResponse::class],
        'list' => ['task-list/default', new ListTasksRequest, TaskGroupsResponse::class],
        'empty list' => ['task-list/empty', new ListTasksRequest, TaskGroupsResponse::class],
        'create' => ['task-create/created', new CreateTaskRequest(1, 'Add the tasks CLI', 'Brief'), TaskGroupResponse::class],
        'show' => ['task-show/default', new ShowTaskRequest(1), TaskGroupResponse::class],
        'update' => ['task-update/updated', new UpdateTaskRequest(1, 'Add the tasks command family'), TaskGroupResponse::class],
        'complete' => ['task-complete/completed', new CompleteTaskGroupRequest(2), TaskGroupResponse::class],
        'subtask create' => ['task-create/child-created', new CreateTaskRequest(projectId: null, title: 'Document the commands', brief: 'Brief', parentId: 1), SubtaskResponse::class],
        'subtask update' => ['task-update/child-updated', new UpdateTaskRequest(1, position: 2), SubtaskResponse::class],
        'subtask destroy' => ['task-destroy/child-destroyed', new DestroyTaskRequest(1), SubtaskResponse::class],
        'subtask cancel' => ['task-cancel/child-cancelled', new CancelTaskRequest(1, 'cancel.child'), SubtaskResponse::class],
        'group comment request' => ['task-comment-create/parent-requested', new CreateTaskCommentRequest(1, 'assistance_requested', 'May I republish?', 'driver'), TaskCommentResponse::class],
        'group comment resolution' => ['task-comment-create/parent-resolved', new CreateTaskCommentRequest(1, 'resolution', 'Approved.', 'nick'), TaskCommentResponse::class],
        'group comment list' => ['task-comment-list/parent-default', new ListTaskCommentsRequest(1), TaskCommentsResponse::class],
        'empty group comment list' => ['task-comment-list/parent-empty', new ListTaskCommentsRequest(1), TaskCommentsResponse::class],
        'comment create' => ['task-comment-create/created', new CreateTaskCommentRequest(1, 'resolution', 'Body', 'nick'), TaskCommentResponse::class],
        'comment list' => ['task-comment-list/default', new ListTaskCommentsRequest(1), TaskCommentsResponse::class],
        'question list' => ['task-question-list/default', new ListTaskQuestionsRequest, TaskQuestionsResponse::class],
        'empty question list' => ['task-question-list/empty', new ListTaskQuestionsRequest, TaskQuestionsResponse::class],
        'question close' => ['task-question-close/closed', new CloseTaskQuestionRequest(1, 'superseded', 'A later subtask owns the continuation.'), TaskQuestionResponse::class],
        'agents' => ['task-agent-list/default', new ListTaskAgentsRequest(1), TaskAgentsResponse::class],
        'egress' => ['task-egress/default', new ListTaskEgressRequest(1), TaskEgressResponse::class],
        'empty egress' => ['task-egress/empty', new ListTaskEgressRequest(1), TaskEgressResponse::class],
        'agent show' => ['task-agent-show/default', new ShowTaskAgentRequest(1, 3), TaskAgentSnapshotResponse::class],
        'archived agent show' => ['task-agent-show/archived', new ShowTaskAgentRequest(1, 4), TaskAgentSnapshotResponse::class],
        'agent snapshot after cursor' => ['task-agent-show/after-sequence', new ShowTaskAgentRequest(1, 3, 'run-1.7'), TaskAgentSnapshotResponse::class],
        'pending agent show' => ['task-agent-show/pending', new ShowTaskAgentRequest(1, 5), TaskAgentSnapshotResponse::class],
    ]);

    it('keeps recorded transcripts and null cursors for running and ended threads', function (): void {
        $snapshot = task_fixture_send('task-agent-show/default', new ShowTaskAgentRequest(1, 3));

        expect($snapshot)->toBeInstanceOf(TaskAgentSnapshotResponse::class)
            ->and($snapshot->threadId)->toBe(3)
            ->and($snapshot->state)->toBe('working')
            ->and($snapshot->cursor)->toBeNull()
            ->and($snapshot->archived)->toBeTrue()
            ->and(array_column($snapshot->entries, 'kind'))->toBe(['message', 'activity'])
            ->and($snapshot->entries[1]['text'])->toBe('bash · succeeded · 1200 ms')
            ->and($snapshot->tokens)->toBe(48_200)
            ->and($snapshot->toArray())->toMatchArray(['thread_id' => 3, 'kind' => 'snapshot', 'cursor' => null, 'archived' => true, 'request_id' => task_request_id()]);

        $archived = task_fixture_send('task-agent-show/archived', new ShowTaskAgentRequest(1, 4));

        expect($archived)->toBeInstanceOf(TaskAgentSnapshotResponse::class)
            ->and($archived->archived)->toBeTrue()
            ->and($archived->cursor)->toBeNull()
            ->and($archived->state)->toBe('done')
            ->and($archived->inputRequests)->toBe([])
            ->and($archived->modelCalls)->toBeNull()
            ->and($archived->entries[0]['text'])->toBe('Approved.');

        $afterSequence = task_fixture_send('task-agent-show/after-sequence', new ShowTaskAgentRequest(1, 3, 'run-1.7'));
        $pending = task_fixture_send('task-agent-show/pending', new ShowTaskAgentRequest(1, 5));

        expect($afterSequence)->toBeInstanceOf(TaskAgentSnapshotResponse::class)
            ->and($afterSequence->kind)->toBe('snapshot')->and($afterSequence->cursor)->toBeNull()
            ->and(array_column($afterSequence->entries, 'id'))->toBe(['e1', 'call-1', 'e3'])
            ->and($pending)->toBeInstanceOf(TaskAgentSnapshotResponse::class)
            ->and($pending->kind)->toBe('snapshot')->and($pending->state)->toBeNull()
            ->and($pending->entries)->toBe([])->and($pending->cursor)->toBeNull();
    });

    it('keeps the review-and-merge state of a task and of tasks status', function (): void {
        $group = task_fixture_send('task-show/review-and-merge', new ShowTaskRequest(1));
        $status = task_fixture_send('task-status/merges', new ShowTasksStatusRequest);
        $plain = task_fixture_send('task-show/default', new ShowTaskRequest(1));

        expect($group)->toBeInstanceOf(TaskGroupResponse::class)
            ->and($status)->toBeInstanceOf(TasksStatusResponse::class)
            ->and($plain)->toBeInstanceOf(TaskGroupResponse::class);
        assert($group instanceof TaskGroupResponse && $status instanceof TasksStatusResponse && $plain instanceof TaskGroupResponse);

        expect($group->reviewAndMerge?->enabled)->toBeTrue()
            ->and($group->reviewAndMerge?->prBranch)->toBe('cursor/login-throttle')
            ->and($group->reviewAndMerge?->mergeStatus)->toBe('waiting')
            ->and($group->reviewAndMerge?->reviewedCommits[0]['sha'] ?? null)->toBe(str_repeat('a', 40))
            ->and($group->reviewAndMerge?->reviewedCommits[0]['source'] ?? null)->toBe('pull_request_review')
            ->and($group->reviewAndMerge?->reviewedCommits[0]['github_review_id'] ?? null)->toBe(3311)
            ->and($group->toArray()['review_and_merge']['pr_branch'] ?? null)->toBe('cursor/login-throttle')
            ->and($plain->reviewAndMerge)->toBeNull()
            ->and($plain->toArray())->not->toHaveKey('review_and_merge')
            ->and($status->merges)->toHaveCount(1)
            ->and($status->merges[0]->reference())->toBe('ORB-1')
            ->and($status->merges[0]->mergeStatus)->toBe('waiting')
            ->and($status->toArray()['merges'][0]['pr_branch'] ?? null)->toBe('cursor/login-throttle');
    });

    it('keeps the Gateway fields of a group and its ordered subtasks', function (): void {
        $group = task_fixture_send('task-show/default', new ShowTaskRequest(1));

        expect($group)->toBeInstanceOf(TaskGroupResponse::class);
        assert($group instanceof TaskGroupResponse);

        expect($group->toArray())->not->toHaveKey('execution_mode');

        expect($group->reference())->toBe('ORB-1')
            ->and($group->status)->toBe('backlog')
            ->and($group->project)->toBe('orbit')
            ->and($group->assistanceRequested)->toBeFalse()
            ->and($group->assistanceKind)->toBeNull()
            ->and($group->assistanceQuestion)->toBeNull()
            ->and($group->assistanceReason)->toBeNull()
            ->and($group->questions)->toBe(0)
            ->and($group->escalations)->toBe(0)
            ->and($group->subtasks[0]->assistanceRequested)->toBeFalse()
            ->and($group->subtasks[0]->assistanceKind)->toBeNull()
            ->and($group->subtasks[0]->assistanceQuestion)->toBeNull()
            ->and($group->subtasks[0]->assistanceReason)->toBeNull()
            ->and($group->subtasks[0]->questions)->toBe(0)
            ->and($group->subtasks[0]->escalations)->toBe(0)
            ->and(array_map(static fn (SubtaskResponse $task): int => $task->position, $group->subtasks))->toBe([1, 2])
            ->and($group->toArray())->not->toHaveKey('tasks.0.request_id')
            ->and($group->toArray()['subtasks'][0])->not->toHaveKey('request_id')
            ->and($group->toArray()['request_id'])->toBe(task_request_id());
    });

    it('lists assisted groups on tasks status', function (): void {
        $enabled = task_fixture_send('task-status/enabled', new ShowTasksStatusRequest);
        $clear = task_fixture_send('task-status/enabled', new ShowTasksStatusRequest);
        $assisted = task_fixture_send('task-status/assistance', new ShowTasksStatusRequest);

        assert($enabled instanceof TasksStatusResponse && $clear instanceof TasksStatusResponse && $assisted instanceof TasksStatusResponse);

        expect($enabled->enabled)->toBeTrue()
            ->and($enabled->lastTickAt)->toBe('2026-09-23T10:00:00.000000Z')
            ->and($enabled->toArray()['last_tick_at'] ?? null)->toBe('2026-09-23T10:00:00.000000Z')
            ->and($assisted->lastTickAt)->toBeNull()
            ->and($assisted->toArray())->toHaveKey('last_tick_at', null)
            ->and($clear->assistance)->toBe([])
            ->and($clear->toArray()['assistance'])->toBe([])
            ->and(array_map(static fn (TaskAssistanceResponse $group): string => $group->reference(), $assisted->assistance ?? []))->toBe(['ORB-1', 'ORB-2'])
            ->and($assisted->toArray()['assistance'][0])->toMatchArray([
                'id' => 1,
                'project' => 'orbit',
                'project_code' => 'ORB',
                'title' => 'Blocked implementer',
                'status' => 'running',
                'assistance_kind' => 'failure',
                'assistance_question' => null,
                'assistance_reason' => 'The implementer is blocked.',
            ])
            ->and($assisted->toArray()['assistance'][1])->toMatchArray([
                'title' => 'Settling question',
                'status' => 'settling',
                'assistance_kind' => 'failure',
                'assistance_question' => null,
                'assistance_reason' => 'Which database should this use?',
            ])
            ->and($assisted->toArray()['assistance'])->toHaveCount(2);
    });

    it('refuses a tasks status whose last tick is not a string', function (): void {
        expect(static fn (): TasksStatusResponse => TasksStatusResponse::fromGatewayData(['enabled' => true, 'assistance' => [], 'last_tick_at' => 1_791_352_800], 'request-id'))
            ->toThrow(GatewayApiException::class, 'Gateway response contains an invalid tasks extension status.');
    });

    it('keeps an assistance request on the group and each subtask', function (): void {
        $mockClient = new MockClient([ShowTaskRequest::class => MockResponse::make([
            'data' => [
                'id' => 4,
                'project_id' => 1,
                'title' => 'Stalled',
                'orchestration' => 'tick',
                'brief' => 'The group is waiting.',
                'status' => 'running',
                'assistance_requested' => true,
                'assistance_kind' => 'direction',
                'assistance_question' => 'Which database should this use?',
                'assistance_reason' => 'The implementer is blocked.',
                'questions' => 3,
                'escalations' => 1,
                'subtasks' => [
                    [
                        'id' => 8,
                        'parent_id' => 4,
                        'position' => 1,
                        'title' => 'Blocked step',
                        'brief' => 'Needs a decision.',
                        'status' => 'running',
                        'assistance_requested' => true,
                        'assistance_kind' => 'direction',
                        'assistance_question' => 'Which database should this use?',
                        'assistance_reason' => 'The implementer is blocked.',
                        'questions' => 2,
                        'escalations' => 1,
                    ],
                    [
                        'id' => 9,
                        'parent_id' => 4,
                        'position' => 2,
                        'title' => 'Later step',
                        'brief' => 'Not blocked.',
                        'status' => 'todo',
                        'assistance_requested' => false,
                        'assistance_reason' => null,
                    ],
                ],
            ],
            'meta' => ['request_id' => task_request_id()],
        ])]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);
        $group = $connector->send(new ShowTaskRequest(4))->dto();

        expect($group)->toBeInstanceOf(TaskGroupResponse::class);
        assert($group instanceof TaskGroupResponse);
        expect($group->assistanceKind)->toBe('direction')
            ->and($group->assistanceQuestion)->toBe('Which database should this use?')
            ->and($group->questions)->toBe(3)
            ->and($group->escalations)->toBe(1)
            ->and($group->subtasks[0]->questions)->toBe(2)
            ->and($group->subtasks[0]->escalations)->toBe(1)
            ->and($group->subtasks[1]->questions)->toBe(0)
            ->and($group->subtasks[1]->escalations)->toBe(0)
            ->and($group->toArray())->toMatchArray([
                'assistance_requested' => true,
                'assistance_kind' => 'direction',
                'assistance_question' => 'Which database should this use?',
                'assistance_reason' => 'The implementer is blocked.',
                'questions' => 3,
                'escalations' => 1,
            ])->and($group->toArray()['subtasks'][0])->toMatchArray([
                'assistance_requested' => true,
                'assistance_kind' => 'direction',
                'assistance_question' => 'Which database should this use?',
                'assistance_reason' => 'The implementer is blocked.',
                'questions' => 2,
                'escalations' => 1,
            ])->and($group->toArray()['subtasks'][1])->toMatchArray([
                'assistance_requested' => false,
                'assistance_kind' => null,
                'assistance_question' => null,
                'assistance_reason' => null,
                'questions' => 0,
                'escalations' => 0,
            ]);
    });

    it('sends the close status and reason, and reads the closed question', function (): void {
        $request = new CloseTaskQuestionRequest(1, 'superseded', 'A later subtask owns the continuation.');
        $question = task_fixture_send('task-question-close/closed', $request);

        expect($request->body()->all())->toBe('{"status":"superseded","reason":"A later subtask owns the continuation."}')
            ->and($question)->toBeInstanceOf(TaskQuestionResponse::class);
        assert($question instanceof TaskQuestionResponse);
        expect($question->status)->toBe('superseded')
            ->and($question->answeredBy)->toBe('operator')
            ->and($question->answer)->toBe('A later subtask owns the continuation.')
            ->and($question->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    });

    it('reads the task question list', function (): void {
        $questions = task_fixture_send('task-question-list/default', new ListTaskQuestionsRequest);

        expect($questions)->toBeInstanceOf(TaskQuestionsResponse::class);
        assert($questions instanceof TaskQuestionsResponse);

        expect($questions->questions)->toHaveCount(2)
            ->and($questions->questions[0]->reference())->toBe('#13/57')
            ->and($questions->questions[0]->askedBy)->toBe('reviewer')
            ->and($questions->questions[0]->status)->toBe('answered')
            ->and($questions->questions[0]->cause)->toBe('contract_gap')
            ->and($questions->questions[0]->answer)->toBe('Follow ADR 0187.')
            ->and($questions->questions[0]->escalatedAt)->not->toBeNull()
            ->and($questions->questions[1]->askedBy)->toBe('implementer')
            ->and($questions->questions[1]->status)->toBe('open')
            ->and($questions->questions[1]->cause)->toBeNull()
            ->and($questions->questions[1]->answer)->toBeNull()
            ->and($questions->questions[1]->answeredBy)->toBeNull()
            ->and($questions->toArray()['questions'][0])->not->toHaveKey('request_id')
            ->and($questions->toArray()['questions'][0])->toMatchArray([
                'id' => 2,
                'task_id' => 13,
                'subtask_id' => 57,
                'attempt' => 2,
                'asked_by' => 'reviewer',
                'question' => 'Which ADR decides the database?',
                'status' => 'answered',
                'answered_by' => 'operator',
                'answer' => 'Follow ADR 0187.',
                'cause' => 'contract_gap',
            ]);

        $empty = task_fixture_send('task-question-list/empty', new ListTaskQuestionsRequest);

        expect($empty)->toBeInstanceOf(TaskQuestionsResponse::class);
        assert($empty instanceof TaskQuestionsResponse);
        expect($empty->questions)->toBe([])
            ->and($empty->toArray()['questions'])->toBe([]);
    });

    it('keeps the watched pull request separate from the published pull request', function (): void {
        $group = task_fixture_send('task-show/watched', new ShowTaskRequest(1));
        assert($group instanceof TaskGroupResponse);

        expect($group->watchedPrUrl)->toBe('https://github.com/hardimpactdev/orbit/pull/451')
            ->and($group->watchedPrNumber)->toBe(451)
            ->and($group->watchedPrState)->toBe('open')
            ->and($group->prUrl)->toBeNull()
            ->and($group->toArray())->toMatchArray([
                'watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/451',
                'watched_pr_number' => 451,
                'watched_pr_state' => 'open',
                'pr_url' => null,
            ]);
        $unwatched = task_fixture_send('task-show/default', new ShowTaskRequest(1));
        assert($unwatched instanceof TaskGroupResponse);
        expect($unwatched->watchedPrUrl)->toBeNull()
            ->and($unwatched->watchedPrNumber)->toBeNull()
            ->and($unwatched->watchedPrState)->toBeNull();
    });

    it('replays the complete recorded review fixup through existing brief and deliverables', function (): void {
        $group = task_fixture_send('task-show/review-fixup', new ShowTaskRequest(1));
        assert($group instanceof TaskGroupResponse);
        $fixup = $group->subtasks[2];
        $lines = array_filter(explode("\n", $fixup->brief), static fn (string $line): bool => str_starts_with($line, '> '));
        $source = json_decode(implode("\n", array_map(static fn (string $line): string => substr($line, 2), $lines)), true, flags: JSON_THROW_ON_ERROR);

        expect($fixup->title)->toBe('Address GitHub review findings from account 42')
            ->and($source['review']['reviewer_id'])->toBe(42)
            ->and($source['review']['id'])->toBe(101)
            ->and($source['review']['state'])->toBe('CHANGES_REQUESTED')
            ->and($source['review']['body'])->not->toBeEmpty()
            ->and($source['comments'])->toHaveCount(1)
            ->and($source['comments'][0]['body'])->not->toBeEmpty()
            ->and($source['comments'][0]['diff_hunk'])->not->toBeEmpty()
            ->and($fixup->brief)->toContain('Internal approval is not GitHub re-review.')
            ->and($fixup->deliverables[0]['id'])->toBe('review-findings')
            ->and($fixup->deliverables[0]['type'])->toBe('review')
            ->and($fixup->deliverables)->toHaveCount(1)
            ->and($fixup->toArray()['brief'])->toBe($fixup->brief)
            ->and($fixup->toArray()['deliverables'])->toBe($fixup->deliverables);
    });

    it('keeps fails_on_base on a test deliverable', function (): void {
        $mockClient = new MockClient([CreateTaskRequest::class => MockResponse::make([
            'data' => [
                'id' => 1,
                'parent_id' => 1,
                'position' => 1,
                'title' => 'Layout',
                'brief' => 'Fix the layout.',
                'status' => 'todo',
                'deliverables' => [[
                    'id' => 'layout-repro',
                    'type' => 'test',
                    'description' => 'Fails before the fix',
                    'fails_on_base' => true,
                ]],
            ],
            'meta' => ['request_id' => task_request_id()],
        ])]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);
        $subtask = $connector->send(new CreateTaskRequest(projectId: null, title: 'Layout', brief: 'Fix the layout.', parentId: 1))->dto();

        expect($subtask)->toBeInstanceOf(SubtaskResponse::class);
        assert($subtask instanceof SubtaskResponse);
        expect($subtask->deliverables[0]['fails_on_base'])->toBeTrue();
    });

    it('keeps comments newest first and agent errors', function (): void {
        $comments = task_fixture_send('task-comment-list/default', new ListTaskCommentsRequest(1));
        $agents = task_fixture_send('task-agent-list/default', new ListTaskAgentsRequest(1));

        assert($comments instanceof TaskCommentsResponse && $agents instanceof TaskAgentsResponse);

        expect(array_map(static fn (TaskCommentResponse $comment): string => $comment->type, $comments->comments))
            ->toBe(['resolution', 'approved', 'ready_for_review'])
            ->and($comments->comments[1]->commitSha)->toBe('3f2a9c1e5b7d4f6a8c0e2b4d6f8a0c2e4b6d8f0a')
            ->and($agents->agents[1]->observationError)->toBe('T3 did not answer in time.')
            ->and($agents->agents[0]->inputTokens)->toBe(2100)
            ->and($agents->agents[0]->cachedInputTokens)->toBe(40100)
            ->and($agents->agents[0]->outputTokens)->toBe(6000)
            ->and($agents->agents[0]->modelCalls)->toBe(4)
            ->and($agents->agents[0]->peakContextTokens)->toBe(9800)
            ->and($agents->agents[1]->inputTokens)->toBeNull()
            ->and($agents->agents[1]->peakContextTokens)->toBeNull()
            // A thread whose last turn asked keeps its questions in the agent's own words.
            ->and($agents->agents[0]->questions)->toBeNull()
            ->and($agents->agents[1]->questions[0]['question'] ?? null)->toBe('Which color?')
            ->and($agents->toArray()['agents'][1]['questions'][0]['options'][1]['label'] ?? null)->toBe('Blue')
            ->and($agents->toArray()['agents'][0])->not->toHaveKey('request_id');
    });

    it('keeps the destination order, the null port of a name and the sources of an egress row', function (): void {
        $egress = task_fixture_send('task-egress/default', new ListTaskEgressRequest(1));

        assert($egress instanceof TaskEgressResponse);

        expect(array_map(static fn ($row): string => $row->destination, $egress->rows))->toBe(['140.82.121.4', 'example.com', 'registry.npmjs.org'])
            ->and($egress->rows[1]->port)->toBeNull()->and($egress->rows[1]->protocol)->toBeNull()
            ->and($egress->rows[2]->port)->toBe(443)->and($egress->rows[2]->sources)->toBe(['conn', 'dns'])->and($egress->rows[2]->addresses)->toBe(['104.16.1.34', '104.16.2.34'])
            ->and($egress->toArray()['hosts'][0])->not->toHaveKey('request_id');
    });

    it('preserves the Gateway error code of a recorded refusal', function (string $fixture, GatewayRequest $request, string $code): void {
        try {
            task_fixture_send($fixture, $request);
            $this->fail('The refusal did not throw.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe($code);
        }
    })->with([
        'group closed' => ['task-comment-create/parent-group-closed', new CreateTaskCommentRequest(1, 'resolution', 'Approved.', 'nick'), 'tasks.group_closed'],
        'no subtasks' => ['task-create/no-subtasks', new CreateTaskRequest(1, 'Empty', 'No subtasks.', 'todo'), 'tasks.no_subtasks'],
        'not in backlog' => ['task-update/not-in-backlog', new UpdateTaskRequest(1, 'Too late'), 'tasks.not_in_backlog'],
        'subtask not running' => ['task-cancel/child-not-running', new CancelTaskRequest(1, 'cancel.child'), 'tasks.subtask_not_running'],
        'subtask interrupt failed' => ['task-cancel/child-interrupt-failed', new CancelTaskRequest(3, 'cancel.child'), 'tasks.subtask_interrupt_failed'],
    ]);

    it('rejects malformed task records', function (GatewayRequest $request, mixed $data): void {
        $mockClient = new MockClient([$request::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => task_request_id()]])]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        expect(fn (): mixed => $connector->send($request)->dto())->toThrow(GatewayApiException::class);
    })->with([
        'status without enabled' => [new ShowTasksStatusRequest, ['enabled' => 'yes']],
        'status assistance is not a list' => [new ShowTasksStatusRequest, ['enabled' => true, 'assistance' => 'blocked']],
        'status assistance entry without an id' => [new ShowTasksStatusRequest, ['enabled' => true, 'assistance' => [['title' => 'Stalled', 'status' => 'running']]]],
        'group without title' => [new ShowTaskRequest(1), ['id' => 1, 'project_id' => 1, 'brief' => 'B', 'status' => 'backlog', 'tasks' => []]],
        'group with scalar subtasks' => [new ShowTaskRequest(1), ['id' => 1, 'project_id' => 1, 'title' => 'T', 'brief' => 'B', 'status' => 'backlog', 'tasks' => 'none']],
        'group list member without id' => [new ListTasksRequest, [['title' => 'T']]],
        'subtask without position' => [new CreateTaskRequest(projectId: null, title: 'T', brief: 'B', parentId: 1), ['id' => 1, 'parent_id' => 1, 'title' => 'T', 'brief' => 'B', 'status' => 'todo']],
        'subtask with a scalar deliverable' => [new CreateTaskRequest(projectId: null, title: 'T', brief: 'B', parentId: 1), ['id' => 1, 'parent_id' => 1, 'position' => 1, 'title' => 'T', 'brief' => 'B', 'status' => 'todo', 'deliverables' => ['docs']]],
        'comment without author' => [new CreateTaskCommentRequest(1, 'resolution', 'B', 'nick'), ['id' => 1, 'parent_id' => 1, 'task_id' => 1, 'type' => 'resolution', 'body' => 'B', 'posted_at' => 'now']],
        'agent without driver' => [new ListTaskAgentsRequest(1), [['id' => 1, 'parent_id' => 1, 'role' => 'reviewer', 'external_id' => 'x']]],
        'agent snapshot without its source' => [new ShowTaskAgentRequest(1, 3), ['thread_id' => 3, 'kind' => 'snapshot', 'entries' => []]],
        'agent snapshot with an entry without text' => [new ShowTaskAgentRequest(1, 3), ['thread_id' => 3, 'kind' => 'snapshot', 'archived' => true, 'entries' => [['id' => 'e1', 'kind' => 'message', 'label' => 'user', 'at' => 'now']]]],
        'agent snapshot with entries that are not a list' => [new ShowTaskAgentRequest(1, 3), ['thread_id' => 3, 'kind' => 'snapshot', 'archived' => false, 'entries' => 'none']],
        'group with a question count that is not an integer' => [new ShowTaskRequest(1), ['id' => 1, 'project_id' => 1, 'title' => 'T', 'brief' => 'B', 'status' => 'backlog', 'questions' => 'many', 'tasks' => []]],
        'question without an id' => [new ListTaskQuestionsRequest, [['task_id' => 1, 'subtask_id' => 2, 'attempt' => 1, 'asked_by' => 'implementer', 'question' => 'Which database?', 'status' => 'open', 'asked_at' => '2026-10-07T00:00:00+00:00']]],
    ]);
});

function task_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

/** Replays a recorded Gateway response from fixtures/tasks and returns the request's DTO. */
function task_fixture_send(string $fixture, GatewayRequest $request): object
{
    $recorded = json_decode(
        (string) file_get_contents(dirname(__DIR__, levels: 4)."/fixtures/tasks/{$fixture}.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $mockClient = new MockClient([$request::class => MockResponse::make($recorded['body'], $recorded['status'])]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    $dto = $connector->send($request)->dto();
    assert(is_object($dto));

    return $dto;
}

it('bounds observed VM power without inferring it from group status', function (mixed $value, ?string $expected): void {
    $group = TaskGroupResponse::fromGatewayData([
        'id' => 1, 'project_id' => 1, 'title' => 'Sandbox', 'brief' => 'Power is separate.',
        'orchestration' => 'tick', 'status' => 'waiting_for_review', 'questions' => 0, 'escalations' => 0,
        'vm_power' => $value,
    ], '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

    expect($group->vmPower)->toBe($expected)
        ->and($group->toArray()['vm_power'] ?? null)->toBe($expected);
    if ($expected === null) {
        expect($group->toArray())->not->toHaveKey('vm_power');
    }
})->with([
    ['running', 'running'], ['stopped', 'stopped'], ['destroyed', 'destroyed'],
    [null, null], ['starting', null], [['running'], null], [true, null],
]);

it('accepts task groups with missing power and ignores unknown response fields', function (): void {
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/fixtures/tasks/task-show/default.json'), true, flags: JSON_THROW_ON_ERROR);
    $data = $fixture['body']['data'];
    unset($data['vm_power']);
    $data['future_optional_field'] = 'running';

    $group = TaskGroupResponse::fromGatewayData($data, task_request_id());

    expect($group->vmPower)->toBeNull()
        ->and($group->status)->toBe($data['status'])
        ->and($group->toArray())->not->toHaveKey('vm_power');
});

it('transports preview omission and explicit booleans', function (?bool $preview): void {
    foreach ([new CreateTaskRequest(1, 'Work', 'Brief', preview: $preview), new UpdateTaskRequest(1, preview: $preview)] as $request) {
        $body = json_decode($request->body()->all(), true, flags: JSON_THROW_ON_ERROR);
        if ($preview === null) {
            expect($body)->not->toHaveKey('preview');
        } else {
            expect($body['preview'])->toBe($preview);
        }
    }
})->with([null, false, true]);

it('transports declared topology including an explicit empty replacement', function (): void {
    expect(json_decode(new CreateTaskRequest(projectId: null, title: 'Step', brief: 'Work', parentId: 13, topology: ['app-dev'])->body()->all(), true))
        ->toBe(['parent_id' => 13, 'title' => 'Step', 'brief' => 'Work', 'topology' => ['app-dev']]);
    expect(json_decode(new UpdateTaskRequest(57, topology: [])->body()->all(), true))->toBe(['topology' => []]);
    expect(new SubtaskInput('Step', 'Work', topology: ['app-prod-2'])->toArray())
        ->toBe(['title' => 'Step', 'brief' => 'Work', 'topology' => ['app-prod-2']]);
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/fixtures/tasks/task-create/child-created.json'), true, flags: JSON_THROW_ON_ERROR);
    $data = [...$fixture['body']['data'], 'topology' => ['app-dev', 'app-prod']];
    $result = SubtaskResponse::fromGatewayData($data, task_request_id());
    expect($result->topology)->toBe(['app-dev', 'app-prod']);
    expect($result->toArray()['topology'])->toBe(['app-dev', 'app-prod']);
});

it('rejects malformed topology response types', function (mixed $topology): void {
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/fixtures/tasks/task-create/child-created.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(fn () => SubtaskResponse::fromGatewayData([...$fixture['body']['data'], 'topology' => $topology], task_request_id()))
        ->toThrow(GatewayApiException::class);
})->with(['scalar' => ['app-dev'], 'non-string' => [[1]], 'object' => [['name' => 'app-dev']]]);

it('transports an explicit orchestration mode', function (string $mode): void {
    $request = new CreateTaskRequest(1, 'Work', 'Brief', orchestration: $mode);
    expect(json_decode($request->body()->all(), true, flags: JSON_THROW_ON_ERROR)['orchestration'])->toBe($mode);
})->with(['tick', 'external']);

it('returns the external orchestration mode from the Gateway', function (): void {
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/fixtures/tasks/task-create/external.json'), true, flags: JSON_THROW_ON_ERROR);
    $group = TaskGroupResponse::fromGatewayData($fixture['body']['data'], task_request_id());
    expect($group->orchestration)->toBe('external')->and($group->toArray()['orchestration'])->toBe('external');
});

it('preserves external board status inputs on partial updates', function (): void {
    expect(new UpdateTaskRequest(13, status: 'reviewing')->body()->all())->toBe('{"status":"reviewing"}');
    expect(new UpdateTaskRequest(57, status: 'completed')->body()->all())->toBe('{"status":"completed"}');
    expect(new UpdateTaskRequest(57, status: '')->body()->all())->toBe('{"status":""}');
});

it('transports keyed native cancellation and parses the accepted Operation', function (): void {
    $request = new CancelTaskRequest(13, 'cancel.13');
    $mock = new MockClient([CancelTaskRequest::class => MockResponse::make(['data' => [
        'id' => 42, 'kind' => 'tasks.cancel', 'target_type' => 'task_group', 'target_id' => 13,
        'status' => 'queued', 'sequence' => 1,
    ]], 202)]);
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);
    $operation = $connector->send($request)->dto()->result;
    expect($operation)->toBeInstanceOf(OperationResponse::class)
        ->and($operation->kind)->toBe('tasks.cancel')->and($operation->status)->toBe('queued')
        ->and($request->headers()->get('Idempotency-Key'))->toBe('cancel.13')
        ->and($request->body()->all())->toBe('{}');
});
