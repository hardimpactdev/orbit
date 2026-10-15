<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Operations\CancelOperationRequest;
use Orbit\Sdk\Requests\Operations\ListOperationsRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
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
