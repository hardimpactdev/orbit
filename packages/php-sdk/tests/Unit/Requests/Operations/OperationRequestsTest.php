<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Operations\CancelOperationRequest;
use Orbit\Sdk\Requests\Operations\ListOperationsRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Tasks\AnalyzeTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\CheckTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\ProvisionTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\PublishTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\TaskAnalysisReference;
use Orbit\Sdk\Requests\Tasks\TurnTaskGroupRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function operation_payload(array $overrides = []): array
{
    return [
        'id' => 42,
        'kind' => 'instance.deploy',
        'target_type' => 'instance',
        'target_id' => 17,
        'input' => [],
        'status' => 'running',
        'sequence' => 9,
        'current_step' => 'before_activation.migrate',
        'failed_step' => null,
        'error_code' => null,
        'result' => null,
        'cancel_requested' => false,
        'attempts' => 1,
        'triggered_by' => 'laptop',
        'created_at' => '2026-10-15T10:00:00+00:00',
        'started_at' => '2026-10-15T10:00:01+00:00',
        'finished_at' => null,
        'poll_after_seconds' => 3,
        ...$overrides,
    ];
}

function operation_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);

    return $connector;
}

describe('operation requests', function (): void {
    it('starts a task check with typed input and preserves its result and key', function (): void {
        $request = new CheckTaskGroupRequest(17, 21, 'handoff', 'task.check.c1');
        $result = ['outcome' => 'failed', 'deliverables' => [['id' => 'test', 'outcome' => 'failed', 'failures' => ['Command failed.']]],
            'check_id' => 31, 'commit' => null, 'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40), 'output' => 'Failed.'];
        $mock = new MockClient([$request::class => MockResponse::make(['data' => operation_payload([
            'kind' => 'tasks.check', 'target_type' => 'task_group', 'status' => 'succeeded', 'result' => $result,
        ]), 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846']], 202)]);

        $response = operation_connector($mock)->send($request)->dto();

        expect($request->getMethod())->toBe(Method::POST);
        expect($request->resolveEndpoint())->toBe('/api/v1/task-groups/17/check');
        expect($request->body()->all())->toBe(['subtask' => 21, 'phase' => 'handoff']);
        expect($request->headers()->get('Idempotency-Key'))->toBe('task.check.c1');
        expect(new CheckTaskGroupRequest(17, 21, 'baseline')->headers()->get('Idempotency-Key'))->toBeNull();
        expect($response)->toBeInstanceOf(OperationResponse::class);
        expect($response->result)->toBe($result);
        expect($response->succeeded())->toBeTrue();
        expect($response->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a846');
        $mock->assertSentCount(1);
    });

    it('transports turn inputs and the key and returns typed operation evidence', function (): void {
        $request = new TurnTaskGroupRequest(17, 18, 'reviewer', "Review.\nKeep this prompt.", '23', check: 24, idempotencyKey: 'review.c1');
        $mock = new MockClient([
            $request::class => MockResponse::make(['data' => operation_payload([
                'kind' => 'tasks.turn', 'target_type' => 'task_group', 'status' => 'succeeded',
                'result' => ['receipt' => null, 'thread' => 23, 'state' => 'done'],
            ]), 'meta' => []], 202),
        ]);
        $response = operation_connector($mock)->send($request)->dto();

        expect($request->getMethod())->toBe(Method::POST);
        expect($request->resolveEndpoint())->toBe('/api/v1/task-groups/17/turn');
        expect(json_decode((string) $request->body(), true))->toBe(['subtask' => 18, 'role' => 'reviewer', 'prompt' => "Review.\nKeep this prompt.", 'thread' => '23', 'check' => 24]);
        expect($request->headers()->get('Idempotency-Key'))->toBe('review.c1');
        expect($response)->toBeInstanceOf(OperationResponse::class);
        expect($response->result)->toBe(['receipt' => null, 'thread' => 23, 'state' => 'done']);
        $new = new TurnTaskGroupRequest(17, 18, 'implementer', '', 'new', model: '');
        expect(json_decode((string) $new->body(), true))->toBe(['subtask' => 18, 'role' => 'implementer', 'prompt' => '', 'thread' => 'new', 'model' => '']);
        expect($new->headers()->get('Idempotency-Key'))->toBeNull();
        $mock->assertSentCount(1);
    });

    it('preserves exact manifest bytes and complete analysis evidence through typed operation transport', function (): void {
        $manifest = '{"complete":true,"paths":[{"change":"add","path":"new.php"}]}';
        $request = new AnalyzeTaskGroupRequest(17, 18, $manifest, str_repeat('d', 64), str_repeat('a', 40), str_repeat('b', 40), str_repeat('c', 40), 'analysis.c1');
        $artifact = ['content' => "{\n\"complete\":true\n}\n", 'bytes' => 20, 'sha256' => str_repeat('a', 64)];
        $result = ['outcome' => 'action_required', 'artifact' => $artifact, 'provenance' => ['execution_id' => str_repeat('e', 32), 'mechanical_llm_calls' => 0]];
        $mock = new MockClient([$request::class => MockResponse::make(['data' => operation_payload(['kind' => 'tasks.analyze', 'result' => $result]), 'meta' => []], 202)]);
        $response = operation_connector($mock)->send($request)->dto();
        expect($request->getMethod())->toBe(Method::POST)
            ->and($request->resolveEndpoint())->toBe('/api/v1/task-groups/17/analyze')
            ->and((string) $request->body())->toContain(',"plan":'.$manifest.'}')
            ->and(json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR))->toBe(['subtask' => 18, 'plan_sha256' => str_repeat('d', 64), 'base' => str_repeat('a', 40), 'head' => str_repeat('b', 40), 'tree' => str_repeat('c', 40), 'plan' => json_decode($manifest, true, flags: JSON_THROW_ON_ERROR)])
            ->and($request->headers()->get('Idempotency-Key'))->toBe('analysis.c1')
            ->and($response)->toBeInstanceOf(OperationResponse::class)->and($response->result)->toBe($result);
        $duplicates = new AnalyzeTaskGroupRequest(17, 18, '{"paths":[],"paths":[]}', 'digest', 'base', 'head', 'tree', 'key');
        expect((string) $duplicates->body())->toContain('{"paths":[],"paths":[]}');
        $mock->assertSentCount(1);
    });

    it('adds the exact optional analysis reference without changing ordinary turn bodies', function (): void {
        $request = new TurnTaskGroupRequest(17, 18, 'implementer', 'Prompt', 'new', analysis: new TaskAnalysisReference(42, str_repeat('d', 64)));
        expect(json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR))->toBe(['subtask' => 18, 'role' => 'implementer', 'prompt' => 'Prompt', 'thread' => 'new', 'analysis' => ['operation' => 42, 'plan_sha256' => str_repeat('d', 64)]]);
    });

    it('transports a publish check and optional pull request with an idempotency key', function (bool $pull): void {
        $input = $pull ? ['title' => 'Exact title', 'body' => "Exact body\n"] : null;
        $request = new PublishTaskGroupRequest(17, 31, $input, 'task.publish.c1');
        $result = ['branch' => 'task-17', 'commit' => str_repeat('a', 40), 'pull_request_number' => $pull ? 12 : null, 'pull_request_url' => $pull ? 'https://github.com/acme/shop/pull/12' : null];
        $mock = new MockClient([$request::class => MockResponse::make(['data' => operation_payload(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'status' => 'succeeded', 'result' => $result]), 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846']], 202)]);

        $response = operation_connector($mock)->send($request)->dto();

        expect($request->getMethod())->toBe(Method::POST);
        expect($request->resolveEndpoint())->toBe('/api/v1/task-groups/17/publish');
        expect($request->body()->all())->toBe($pull ? ['check' => 31, 'pull_request' => $input] : ['check' => 31]);
        expect($request->headers()->get('Idempotency-Key'))->toBe('task.publish.c1');
        expect(new PublishTaskGroupRequest(17, 31)->headers()->get('Idempotency-Key'))->toBeNull();
        expect($response)->toBeInstanceOf(OperationResponse::class);
        expect($response->result)->toBe($result);
        expect($response->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a846');
        $mock->assertSentCount(1);
    })->with([false, true]);

    it('starts task provision without a body and preserves the key and typed operation result', function (): void {
        $request = new ProvisionTaskGroupRequest(17, 'task.provision.c1');
        $mock = new MockClient([
            $request::class => MockResponse::make(['data' => operation_payload([
                'kind' => 'tasks.provision', 'target_type' => 'task_group', 'status' => 'succeeded',
                'result' => ['instance_id' => 12, 'node_id' => 34, 'starting_commit' => str_repeat('a', 40)],
            ]), 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846']], 202),
        ]);
        $response = operation_connector($mock)->send($request)->dto();

        expect($request->getMethod())->toBe(Method::POST);
        expect($request->resolveEndpoint())->toBe('/api/v1/task-groups/17/provision');
        expect($request)->not->toBeInstanceOf(HasBody::class);
        expect($request->headers()->get('Idempotency-Key'))->toBe('task.provision.c1');
        expect(new ProvisionTaskGroupRequest(17)->headers()->get('Idempotency-Key'))->toBeNull();
        expect($response)->toBeInstanceOf(OperationResponse::class);
        expect($response->kind)->toBe('tasks.provision');
        expect($response->targetType)->toBe('task_group');
        expect($response->result)->toBe(['instance_id' => 12, 'node_id' => 34, 'starting_commit' => str_repeat('a', 40)]);
        $mock->assertSentCount(1);
    });

    it('uses the exact Gateway methods, paths, and queries', function (): void {
        expect([
            [new ShowOperationRequest(42), Method::GET, '/api/v1/operations/42', []],
            [new ShowOperationRequest(42, 7), Method::GET, '/api/v1/operations/42', ['after_sequence' => 7]],
            [new ListOperationsRequest, Method::GET, '/api/v1/operations', []],
            [new ListOperationsRequest('running', 'instance.deploy', 'instance', 17), Method::GET, '/api/v1/operations', ['status' => 'running', 'kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => 17]],
            [new CancelOperationRequest(42), Method::POST, '/api/v1/operations/42/cancel', []],
        ])->each(static function ($case): void {
            [$request, $method, $path, $query] = $case->value;
            expect($request->getMethod())->toBe($method)
                ->and($request->resolveEndpoint())->toBe($path)
                ->and($request->query()->all())->toBe($query);
        });

        expect((string) new CancelOperationRequest(42)->body())->toBe('{}')
            ->and(static fn () => new ShowOperationRequest(0))->toThrow(InvalidArgumentException::class)
            ->and(static fn () => new ShowOperationRequest(1, -1))->toThrow(InvalidArgumentException::class);
    });

    it('maps an operation with its steps and its outcome', function (): void {
        $mock = new MockClient([
            ShowOperationRequest::class => MockResponse::make(['data' => [
                ...operation_payload([
                    'status' => 'failed',
                    'failed_step' => 'before_activation.migrate',
                    'error_code' => 'deployment.step_failed',
                    'result' => ['deployment_id' => 5, 'release' => 'r2', 'selected_release' => 'r1', 'commit' => null],
                    'poll_after_seconds' => null,
                ]),
                'steps' => [[
                    'name' => 'before_activation.migrate', 'position' => 3, 'status' => 'failed', 'resumable' => false, 'sequence' => 9,
                    'started_at' => null, 'finished_at' => null, 'error_code' => 'deployment.step_failed', 'output' => "boom\n", 'output_truncated' => false,
                ]],
            ], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846']], 200, ['X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846']),
        ]);

        $operation = operation_connector($mock)->send(new ShowOperationRequest(42, 8))->dto();

        expect($operation)->toBeInstanceOf(OperationResponse::class)
            ->and($operation->terminal())->toBeTrue()
            ->and($operation->succeeded())->toBeFalse()
            ->and($operation->failedStep)->toBe('before_activation.migrate')
            ->and($operation->result['selected_release'] ?? null)->toBe('r1')
            ->and($operation->steps[0]->output)->toBe("boom\n")
            ->and($operation->toArray()['steps'][0]['name'])->toBe('before_activation.migrate')
            ->and(json_encode($operation->toArray()['input']))->toBe('{}');
    });

    it('lists operations without steps', function (): void {
        $mock = new MockClient([
            ListOperationsRequest::class => MockResponse::make(['data' => [operation_payload(), operation_payload(['id' => 41, 'status' => 'succeeded'])], 'meta' => []]),
        ]);

        $list = operation_connector($mock)->send(new ListOperationsRequest(status: 'running'))->dto();

        expect(array_map(static fn (OperationResponse $operation): int => $operation->id, $list->operations))->toBe([42, 41])
            ->and($list->toArray()['operations'][0])->not->toHaveKey('steps');
    });

    it('refuses a malformed operation', function (): void {
        expect(static fn () => OperationResponse::fromGatewayData(['id' => 'x'], 'r'))->toThrow(InvalidArgumentException::class);
    });
});
