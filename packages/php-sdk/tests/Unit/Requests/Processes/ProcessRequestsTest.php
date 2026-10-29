<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Processes\CreateProcessRequest;
use Orbit\Sdk\Requests\Processes\DestroyProcessRequest;
use Orbit\Sdk\Requests\Processes\InstanceProcessTarget;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Requests\Processes\NodeProcessTarget;
use Orbit\Sdk\Requests\Processes\ProcessLogsRequest;
use Orbit\Sdk\Requests\Processes\RestartProcessRequest;
use Orbit\Sdk\Requests\Processes\StartProcessRequest;
use Orbit\Sdk\Requests\Processes\StopProcessRequest;
use Orbit\Sdk\Requests\Processes\UpdateProcessRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;
use Orbit\Sdk\Responses\Processes\ProcessLogsResponse;
use Orbit\Sdk\Responses\Processes\ProcessMutationResponse;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('ProcessUser', function (): void {
    it('sends the named account in a typed Node Process request', function (): void {
        $mock = new MockClient([CreateProcessRequest::class => MockResponse::make(process_envelope(), 201)]);
        $request = new CreateProcessRequest(target: new NodeProcessTarget(4), name: 'worker', runtime: 'systemd', command: ['/usr/bin/sleep', '60'], user: 'svc-runner');
        process_connector($mock)->send($request);
        expect($mock->getLastPendingRequest()?->getUrl())->toBe('https://10.44.0.1/api/v1/processes')
            ->and($request->getMethod())->toBe(Method::POST)
            ->and($mock->getLastRequest()?->body()->all())->toMatchArray(['target_type' => 'node', 'target_id' => 4, 'user' => 'svc-runner']);
    });

    it('exposes the Process user in its typed response and JSON', function (): void {
        $envelope = process_envelope();
        $envelope['data']['user'] = 'svc-runner';
        $mock = new MockClient([CreateProcessRequest::class => MockResponse::make($envelope, 201)]);
        $response = process_connector($mock)->send(new CreateProcessRequest(target: new NodeProcessTarget(4), name: 'worker', user: 'svc-runner'))->dto()->result;
        expect($response)->toBeInstanceOf(ProcessResponse::class)
            ->and($response->user)->toBe('svc-runner')->and($response->toArray()['user'])->toBe('svc-runner');
    });

    it('preserves explicit user input without SDK policy', function (string $user): void {
        $request = new CreateProcessRequest(target: new NodeProcessTarget(4), name: 'worker', user: $user);
        expect($request->body()->all()['user'])->toBe($user);
    })->with(['', 'root', 'svc-runner']);

    it('omits an absent user', function (): void {
        $request = new CreateProcessRequest(target: new NodeProcessTarget(4), name: 'worker');
        expect($request->body()->all())->not->toHaveKey('user');
    });
});

it('adds a process with the explicit minimal runtime contract', function (): void {
    $mock = new MockClient([
        CreateProcessRequest::class => MockResponse::make(process_envelope(), 201),
    ]);
    $request = new CreateProcessRequest(
        target: new InstanceProcessTarget(7),
        name: 'redis',
        runtime: 'docker',
        command: ['redis-server'],
        image: 'redis:8-alpine',
        workingDirectory: '/data',
        environment: ['APP_MODE' => 'test'],
        ports: ['127.0.0.1:6380:6379/tcp'],
        volumes: [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
        restartPolicy: 'unless-stopped',
        start: true,
    );
    $response = process_connector($mock)->send($request)->dto()->result;

    expect($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/processes')
        ->and($request->body()->all())
        ->toMatchArray([
            'target_type' => 'instance',
            'target_id' => 7,
            'name' => 'redis',
            'runtime' => 'docker',
            'command' => ['redis-server'],
            'image' => 'redis:8-alpine',
            'working_directory' => '/data',
            'environment' => ['APP_MODE' => 'test'],
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
            'restart_policy' => 'unless-stopped',
            'start' => true,
            'keep_alive' => false,
            'restart_on_deploy' => true,
        ])
        ->and($response)
        ->toBeInstanceOf(ProcessResponse::class)
        ->and($response->requestId)
        ->toBe(process_request_id());
});

it('forwards every explicit process field without applying runtime policy', function (): void {
    $request = new CreateProcessRequest(
        target: new InstanceProcessTarget(7),
        name: 'worker',
        runtime: 'systemd',
        command: ['php', 'artisan', 'queue:work'],
        image: 'orbit-sidecar:latest',
        workingDirectory: '/srv/orbit',
        environment: ['APP_MODE' => 'production'],
        ports: ['127.0.0.1:9080:8080/tcp'],
        volumes: [['source' => 'orbit-data', 'target' => '/data', 'read_only' => true]],
        restartPolicy: 'always',
        start: true,
    );

    expect($request->body()->all())->toBe([
        'target_type' => 'instance',
        'target_id' => 7,
        'name' => 'worker',
        'runtime' => 'systemd',
        'command' => ['php', 'artisan', 'queue:work'],
        'restart_policy' => 'always',
        'start' => true,
        'keep_alive' => false,
        'restart_on_deploy' => true,
        'environment' => ['APP_MODE' => 'production'],
        'ports' => ['127.0.0.1:9080:8080/tcp'],
        'volumes' => [['source' => 'orbit-data', 'target' => '/data', 'read_only' => true]],
        'image' => 'orbit-sidecar:latest',
        'working_directory' => '/srv/orbit',
    ]);
});

it('omits every absent optional process field without applying runtime policy', function (): void {
    $request = new CreateProcessRequest(
        target: new InstanceProcessTarget(7),
        name: 'worker',
        runtime: 'systemd',
        command: ['/usr/bin/php', 'artisan', 'queue:work'],
    );

    expect($request->body()->all())->toBe([
        'target_type' => 'instance',
        'target_id' => 7,
        'name' => 'worker',
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
        'restart_policy' => 'never',
        'start' => false,
        'keep_alive' => false,
        'restart_on_deploy' => true,
    ]);
});

it('preserves explicitly supplied empty process collections', function (): void {
    $request = new CreateProcessRequest(
        target: new InstanceProcessTarget(7),
        name: 'redis',
        runtime: 'docker',
        command: ['redis-server'],
        environment: [],
        ports: [],
        volumes: [],
    );

    expect($request->body()->all())->toEqual([
        'target_type' => 'instance',
        'target_id' => 7,
        'name' => 'redis',
        'runtime' => 'docker',
        'command' => ['redis-server'],
        'restart_policy' => 'never',
        'start' => false,
        'keep_alive' => false,
        'restart_on_deploy' => true,
        'environment' => (object) [],
        'ports' => [],
        'volumes' => [],
    ]);
});

it('sends only supplied in-place update fields', function (): void {
    $request = new UpdateProcessRequest(41, 'docker', ['valkey-server'], 'valkey/valkey:8');
    expect($request->resolveEndpoint())->toBe('/api/v1/processes/41')
        ->and($request->getMethod()->value)->toBe('PATCH')
        ->and($request->body()->all())->toBe(['runtime' => 'docker', 'command' => ['valkey-server'], 'image' => 'valkey/valkey:8']);
    $response = ProcessResponse::fromGatewayData(['working_directory' => null], 'request-id');
    expect($response->workingDirectory)->toBeNull()->and($response->toArray()['working_directory'])->toBeNull();
});

it('preserves an omitted optional volume read-only flag', function (): void {
    $request = new CreateProcessRequest(
        target: new InstanceProcessTarget(7),
        name: 'redis',
        runtime: 'docker',
        command: ['redis-server'],
        volumes: [['source' => 'redis-data', 'target' => '/data']],
    );

    expect($request->body()->all())->toMatchArray([
        'volumes' => [['source' => 'redis-data', 'target' => '/data']],
    ]);
});

it('forwards keep-alive without treating restart policy as the same contract', function (): void {
    $request = new CreateProcessRequest(
        target: new InstanceProcessTarget(7),
        name: 'queue',
        runtime: 'systemd',
        command: ['/usr/bin/php', 'artisan', 'queue:work'],
        restartPolicy: 'never',
        start: true,
        keepAlive: true,
    );

    expect($request->body()->all())->toMatchArray([
        'restart_policy' => 'never',
        'start' => true,
        'keep_alive' => true,
    ]);
});

it('adds a node-targeted process with the explicit node selector', function (): void {
    $request = new CreateProcessRequest(
        target: new NodeProcessTarget(4),
        name: 'postgres',
        runtime: 'docker',
        command: ['postgres'],
        image: 'postgres:18',
    );

    expect($request->body()->all())->toMatchArray([
        'target_type' => 'node',
        'target_id' => 4,
        'name' => 'postgres',
        'runtime' => 'docker',
        'command' => ['postgres'],
        'image' => 'postgres:18',
        'restart_policy' => 'never',
        'start' => false,
        'keep_alive' => false,
        'restart_on_deploy' => true,
    ]);
});

it('lists only one target process collection', function (): void {
    $mock = new MockClient([
        ListProcessesRequest::class => MockResponse::make([
            'data' => [process_gateway_data()],
            'meta' => ['request_id' => process_request_id()],
        ]),
    ]);
    $request = new ListProcessesRequest(new InstanceProcessTarget(7));
    $response = process_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/processes')
        ->and($request->query()->all())
        ->toBe(['target_type' => 'instance', 'target_id' => 7])
        ->and($response)
        ->toBeInstanceOf(ProcessesResponse::class)
        ->and($response->processes)
        ->toHaveCount(1)
        ->and($response->processes[0]->targetType)
        ->toBe('instance')
        ->and($response->processes[0]->targetId)
        ->toBe(7)
        ->and($response->processes[0]->toArray())
        ->not->toHaveKey('node_id')
        ->and($response->processes[0]->runtimeConfig)
        ->toBe([
            'image' => 'redis:8-alpine',
            'command' => ['redis-server'],
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [],
        ])
        ->not->toHaveKey('environment');
});

it('lists one node-targeted process collection', function (): void {
    $request = new ListProcessesRequest(new NodeProcessTarget(4));

    expect($request->query()->all())->toBe(['target_type' => 'node', 'target_id' => 4]);
});

it('rejects a domain selector for a process list because the query target id is an integer', function (): void {
    $request = new ListProcessesRequest(new InstanceProcessTarget('commander.test'));

    expect(fn (): array => $request->query()->all())
        ->toThrow(InvalidArgumentException::class, 'Process list target id must be an integer.');
});

it('bounds runtime configuration to a string-keyed response map', function (): void {
    $data = process_gateway_data();
    $data['runtime_config'] = [
        'image' => 'redis:8-alpine',
        0 => 'malformed',
    ];

    $response = ProcessResponse::fromGatewayData($data, process_request_id());

    expect($response->runtimeConfig)->toBe(['image' => 'redis:8-alpine']);
});

it('maps lifecycle and remove endpoints to one typed process response', function (
    string $requestClass,
    Method $method,
    string $endpoint,
): void {
    $request = new $requestClass(12);
    $mock = new MockClient([
        $request::class => MockResponse::make(process_envelope()),
    ]);
    $response = process_connector($mock)->send($request)->dto()->result;

    expect($request->getMethod())
        ->toBe($method)
        ->and($request->resolveEndpoint())
        ->toBe($endpoint)
        ->and($response)
        ->toBeInstanceOf(ProcessResponse::class);
})->with([
    'start' => [StartProcessRequest::class, Method::POST, '/api/v1/processes/12/start'],
    'stop' => [StopProcessRequest::class, Method::POST, '/api/v1/processes/12/stop'],
    'restart' => [RestartProcessRequest::class, Method::POST, '/api/v1/processes/12/restart'],
    'remove' => [DestroyProcessRequest::class, Method::DELETE, '/api/v1/processes/12'],
]);

it('sends lifecycle actions without a JSON request body', function (string $requestClass): void {
    $request = new $requestClass(12);
    $mock = new MockClient([
        $request::class => MockResponse::make(process_envelope()),
    ]);

    process_connector($mock)->send($request);

    $pendingRequest = $mock->getLastPendingRequest();

    expect($pendingRequest?->body())
        ->toBeNull()
        ->and($pendingRequest?->headers()->all())
        ->not
        ->toHaveKey('Content-Type')
        ->and((string) $pendingRequest?->createPsrRequest()->getBody())
        ->toBeEmpty();
})->with([
    'start' => [StartProcessRequest::class],
    'stop' => [StopProcessRequest::class],
    'restart' => [RestartProcessRequest::class],
]);

it('returns one bounded non-streaming log tail', function (): void {
    $mock = new MockClient([
        ProcessLogsRequest::class => MockResponse::make([
            'data' => [
                'id' => 12,
                'name' => 'redis',
                'lines' => 25,
                'logs' => "Authorization: Bearer super-secret-token\n",
            ],
            'meta' => ['request_id' => process_request_id()],
        ]),
    ]);
    $request = new ProcessLogsRequest(12, 25);
    $response = process_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/processes/12/logs')
        ->and($request->query()->all())
        ->toBe(['lines' => 25])
        ->and($response)
        ->toBeInstanceOf(ProcessLogsResponse::class)
        ->and($response->logs)
        ->toBe("Authorization: [REDACTED]\n")
        ->not->toContain('super-secret-token')->and($response->toArray())
        ->not->toHaveKey('follow');
});

function process_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array<string, mixed> */
function process_envelope(): array
{
    return [
        'data' => process_gateway_data(),
        'meta' => ['request_id' => process_request_id()],
    ];
}

/** @return array<string, mixed> */
function process_gateway_data(): array
{
    return [
        'id' => 12,
        'target_type' => 'instance',
        'target_id' => 7,
        'name' => 'redis',
        'runtime' => 'docker',
        'working_directory' => '/data',
        'runtime_config' => [
            'image' => 'redis:8-alpine',
            'command' => ['redis-server'],
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'keep_alive' => false,
        'restart_on_deploy' => true,
        'desired_state' => 'running',
        'status' => 'active',
        'runtime_status' => 'running',
        'failed_step' => null,
        'error_code' => null,
    ];
}

function process_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

it('transports a preset and domain selector without synthesizing runtime configuration', function (): void {
    $mock = new MockClient([CreateProcessRequest::class => MockResponse::make(process_envelope(), 201)]);
    $request = new CreateProcessRequest(target: new InstanceProcessTarget('commander.test'), name: 'assets', preset: 'vp-dev', start: true);
    process_connector($mock)->send($request);

    expect($request->body()->all())->toBe(['target_type' => 'instance', 'target_id' => 'commander.test', 'name' => 'assets', 'preset' => 'vp-dev', 'start' => true, 'keep_alive' => false, 'restart_on_deploy' => true]);
});

it('transports the deploy restart setting and preserves false in the Process response', function (): void {
    $create = new CreateProcessRequest(target: new InstanceProcessTarget(7), name: 'horizon', restartOnDeploy: false);
    $update = new UpdateProcessRequest(41, 'systemd', ['/usr/bin/php', 'artisan', 'horizon'], restartOnDeploy: false);
    expect($create->body()->all()['restart_on_deploy'])->toBeFalse()
        ->and($update->body()->all()['restart_on_deploy'])->toBeFalse()
        ->and(new CreateProcessRequest(target: new InstanceProcessTarget(7), name: 'horizon')->body()->all()['restart_on_deploy'])->toBeTrue()
        ->and(new UpdateProcessRequest(41, 'systemd', ['/usr/bin/php', 'artisan', 'horizon'])->body()->all())->not->toHaveKey('restart_on_deploy');
    $envelope = process_envelope();
    $envelope['data']['restart_on_deploy'] = false;
    $mock = new MockClient([CreateProcessRequest::class => MockResponse::make($envelope, 201)]);
    $response = process_connector($mock)->send($create)->dto()->result;
    expect($response->restartOnDeploy)->toBeFalse()->and($response->toArray()['restart_on_deploy'])->toBeFalse();
});

it('transports a ports-only patch without defaults', function (): void {
    $request = new UpdateProcessRequest(41, ports: ['6380:6379']);
    $mock = new MockClient([UpdateProcessRequest::class => MockResponse::make(process_envelope())]);

    process_connector($mock)->send($request);

    expect($request->body()->all())->toBe(['ports' => ['6380:6379']])
        ->and($mock->getLastPendingRequest()?->getUrl())->toBe('https://10.44.0.1/api/v1/processes/41');
});

it('transports explicit empty collections false values and working directory reset', function (): void {
    $request = new UpdateProcessRequest(41, environment: [], ports: [], volumes: [],
        restartPolicy: 'never', keepAlive: false, restartOnDeploy: false, clearWorkingDirectory: true);

    expect($request->body()->all())->toEqual([
        'environment' => (object) [], 'ports' => [], 'volumes' => [],
        'restart_policy' => 'never', 'keep_alive' => false, 'restart_on_deploy' => false, 'working_directory' => null,
    ]);
});

it('forwards every supplied update field even without a runtime selector', function (): void {
    $request = new UpdateProcessRequest(41, command: ['server'], image: 'server:2', workingDirectory: '/data',
        environment: ['LABEL' => 'literal'], ports: ['8080:80'], volumes: [['source' => 'data', 'target' => '/data']], user: 'worker');

    expect($request->body()->all())->toMatchArray([
        'command' => ['server'], 'image' => 'server:2', 'working_directory' => '/data',
        'ports' => ['8080:80'], 'volumes' => [['source' => 'data', 'target' => '/data']], 'user' => 'worker',
    ])->not->toHaveKey('runtime');
    expect((array) $request->body()->all()['environment'])->toBe(['LABEL' => 'literal']);
});

it('transports an explicit image reset for a runtime switch', function (): void {
    $request = new UpdateProcessRequest(41, runtime: 'systemd', ports: [], volumes: [], clearImage: true);

    expect($request->body()->all())->toBe(['runtime' => 'systemd', 'ports' => [], 'volumes' => [], 'image' => null]);
});

it('preserves the Route listener refusal from a Process update', function (): void {
    $mock = new MockClient([UpdateProcessRequest::class => MockResponse::make([
        'error' => ['code' => 'process.route_upstream_changed', 'message' => 'Route [sync.example.com] needs its listener.',
            'details' => ['route_id' => 12, 'domain' => 'sync.example.com']],
    ], 409)]);

    try {
        process_connector($mock)->send(new UpdateProcessRequest(processId: 267, ports: []))->dto();
        $this->fail('Expected GatewayApiException.');
    } catch (GatewayApiException $exception) {
        expect($exception->errorCode())->toBe('process.route_upstream_changed')
            ->and($exception->getMessage())->toBe('Route [sync.example.com] needs its listener.')
            ->and($exception->details())->toBe(['route_id' => 12, 'domain' => 'sync.example.com']);
    }
    expect($mock->getLastRequest()?->body()->all())->toBe(['ports' => []]);
});

it('keeps accepted mutations as Operations instead of synchronous Process data', function (object $request): void {
    $operation = ['id' => 91, 'kind' => 'process.stop', 'target_type' => 'process', 'target_id' => 12,
        'status' => 'queued', 'sequence' => 1, 'input' => [], 'result' => null];
    $mock = new MockClient([$request::class => MockResponse::make([
        'data' => $operation, 'meta' => ['request_id' => process_request_id()],
    ], 202)]);

    $response = process_connector($mock)->send($request)->dto();

    expect($response)->toBeInstanceOf(ProcessMutationResponse::class)
        ->and($response->result)->toBeInstanceOf(OperationResponse::class)
        ->and($response->result->id)->toBe(91)->and($response->result->status)->toBe('queued')
        ->and($response->result->requestId)->toBe(process_request_id());
})->with([
    'create' => [new CreateProcessRequest(new NodeProcessTarget(4), 'helper')],
    'update' => [new UpdateProcessRequest(12, keepAlive: true)],
    'start' => [new StartProcessRequest(12)],
    'stop' => [new StopProcessRequest(12)],
    'restart' => [new RestartProcessRequest(12)],
    'destroy' => [new DestroyProcessRequest(12)],
]);
