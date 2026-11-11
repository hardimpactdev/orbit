<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Instances\AttachInstanceNodeServiceTargetRequest;
use Orbit\Sdk\Requests\Instances\DetachInstanceNodeServiceTargetRequest;
use Orbit\Sdk\Requests\Instances\ListInstanceNodeServiceTargetsRequest;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetRecordResponse;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetResponse;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetsResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('Node service target transport', function (): void {
    it('reads typed attachment records with no query or body and preserves an empty list', function (bool $empty): void {
        $data = ['id' => 4, 'instance_id' => 12, 'node_id' => 9, 'process_id' => 221, 'protocol' => 'tcp', 'port' => 1025,
            'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT'];
        $requestId = '11111111-1111-4111-8111-111111111111';
        $request = new ListInstanceNodeServiceTargetsRequest(12);
        $mock = new MockClient([ListInstanceNodeServiceTargetsRequest::class => MockResponse::make([
            'data' => $empty ? [] : [[...$data, 'unknown_field' => 'not part of the record']], 'meta' => ['request_id' => $requestId],
        ])]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mock);

        $response = $connector->send($request)->dto();

        expect($request->getMethod())->toBe(Method::GET)
            ->and($request->resolveEndpoint())->toBe('/api/v1/instances/12/node-service-targets')
            ->and($request->query()->all())->toBe([])->and($request)->not->toBeInstanceOf(HasBody::class)
            ->and($response)->toBeInstanceOf(InstanceNodeServiceTargetsResponse::class)
            ->and($response->toArray())->toBe(['targets' => $empty ? [] : [$data], 'request_id' => $requestId]);
        if (! $empty) {
            expect($response->targets[0])->toBeInstanceOf(InstanceNodeServiceTargetRecordResponse::class)
                ->and($response->targets[0]->port)->toBe(1025);
        }
        $mock->assertSentCount(1);
    })->with([false, true]);

    it('preserves the list authority refusal and request ID', function (): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient(new MockClient([ListInstanceNodeServiceTargetsRequest::class => MockResponse::make([
            'error' => ['code' => 'node_access.required', 'message' => 'Node access is required.', 'details' => []],
        ], 403, ['X-Orbit-Request-Id' => $requestId])]));

        try {
            $connector->send(new ListInstanceNodeServiceTargetsRequest(12))->dto();
            test()->fail('A list must preserve Node authority refusals.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe('node_access.required')->and($exception->requestId())->toBe($requestId);
        }
    });

    it('rejects malformed attachment record fields without accepting a string identity', function (): void {
        expect(fn () => InstanceNodeServiceTargetRecordResponse::fromGatewayData(['id' => '4']))
            ->toThrow(InvalidArgumentException::class, 'Invalid Node service target record identity or port.');
    });

    it('sends the explicit attachment contract and maps the bounded result', function (): void {
        $request = new AttachInstanceNodeServiceTargetRequest(12, 221, 'tcp', 1025, 'MAIL_HOST', 'MAIL_PORT');
        $requestId = '11111111-1111-4111-8111-111111111111';
        $data = ['id' => 4, 'instance_id' => 12, 'node_id' => 9, 'process_id' => 221, 'protocol' => 'tcp', 'port' => 1025,
            'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT', 'changed' => true, 'reason_code' => null];
        $mock = new MockClient([AttachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'data' => $data, 'meta' => ['request_id' => $requestId],
        ])]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mock);

        $response = $connector->send($request)->dto();

        expect($request->getMethod())->toBe(Method::POST);
        expect($request->resolveEndpoint())->toBe('/api/v1/instances/12/node-service-targets');
        expect($request->body()->all())->toBe(['process_id' => 221, 'protocol' => 'tcp', 'port' => 1025,
            'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT']);
        expect($response)->toBeInstanceOf(InstanceNodeServiceTargetResponse::class)
            ->and($response->toArray())->toBe([...$data, 'request_id' => $requestId]);
    });

    it('sends detach with the Instance and attachment identities', function (): void {
        $request = new DetachInstanceNodeServiceTargetRequest(12, 4, 'detach-record-4');

        expect($request->getMethod())->toBe(Method::DELETE);
        expect($request->resolveEndpoint())->toBe('/api/v1/instances/12/node-service-targets/4');
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient(new MockClient([DetachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'data' => ['id' => 41, 'kind' => 'instance.node_service_detach', 'target_type' => 'instance', 'target_id' => 12,
                'status' => 'succeeded', 'sequence' => 2, 'result' => ['id' => 4, 'instance_id' => 12, 'process_id' => 221, 'detached' => true]], 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ], 202)]));
        $response = $connector->send($request)->dto();
        expect($response)->toBeInstanceOf(OperationResponse::class)
            ->and($response->status)->toBe('succeeded')
            ->and($response->result)->toBe(['id' => 4, 'instance_id' => 12, 'process_id' => 221, 'detached' => true])
            ->and($response->requestId)->toBe('11111111-1111-4111-8111-111111111111')
            ->and($request->headers()->get('Idempotency-Key'))->toBe('detach-record-4')
            ->and($request->query()->all())->toBe([])
            ->and($request)->not->toBeInstanceOf(HasBody::class);
        expect((new DetachInstanceNodeServiceTargetRequest(12, 4))->headers()->all())->toBe([]);
    });

    it('preserves a record-only cleanup refusal with correlation', function (): void {
        $requestId = '11111111-1111-4111-8111-111111111111';
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient(new MockClient([DetachInstanceNodeServiceTargetRequest::class => MockResponse::make([
            'error' => ['code' => 'instance.node_service_runtime_cleanup_required', 'message' => 'Retry instance:migrate first.', 'details' => []],
            'meta' => ['request_id' => $requestId],
        ], 409, ['X-Orbit-Request-Id' => $requestId])]));

        try {
            $connector->send(new DetachInstanceNodeServiceTargetRequest(12, 4))->dto();
            test()->fail('Detach must refuse the unfinished move.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe('instance.node_service_runtime_cleanup_required')
                ->and($exception->requestId())->toBe($requestId);
        }
    });

    it('omits an unspecified port for Gateway selection', function (): void {
        $request = new AttachInstanceNodeServiceTargetRequest(12, 221, 'tcp', null, 'MAIL_HOST', 'MAIL_PORT');

        expect($request->body()->all())->toBe(['process_id' => 221, 'protocol' => 'tcp', 'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT']);
    });

    it('preserves invalid supplied values for Gateway admission', function (): void {
        $request = new AttachInstanceNodeServiceTargetRequest(12, 10, 'udp', 2525, '', 'MAIL_PORT');

        expect($request->body()->all())->toBe(['process_id' => 10, 'protocol' => 'udp', 'port' => 2525,
            'host_env_key' => '', 'port_env_key' => 'MAIL_PORT']);
    });

    it('rejects malformed remote target results', function (array $change): void {
        $data = ['id' => 4, 'instance_id' => 12, 'node_id' => 9, 'process_id' => 221, 'protocol' => 'tcp', 'port' => 1025,
            'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT', 'changed' => true, 'reason_code' => null];

        expect(fn () => InstanceNodeServiceTargetResponse::fromGatewayData([...$data, ...$change], 'invalid'))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'ID' => [['id' => 0]], 'process ID' => [['process_id' => 0]], 'port' => [['port' => 65536]], 'protocol' => [['protocol' => 'udp']],
        'key' => [['host_env_key' => 'BAD-KEY']], 'change' => [['changed' => 'true']],
        'reason' => [['reason_code' => 'invented.code']],
    ]);
});
