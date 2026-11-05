<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Firewall\AllowFirewallRuleRequest;
use Orbit\Sdk\Requests\Firewall\DenyFirewallRuleRequest;
use Orbit\Sdk\Requests\Firewall\ListFirewallRulesRequest;
use Orbit\Sdk\Requests\Firewall\RemoveFirewallRuleRequest;
use Orbit\Sdk\Responses\Firewall\FirewallNodeRulesResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRulesResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('maps allow and deny requests to port-identity payloads', function (
    string $requestClass,
    string $action,
): void {
    $request = new $requestClass(
        nodeId: 7,
        port: '443',
        source: '192.0.2.0/24',
        protocol: 'tcp',
        label: 'office web',
    );
    $mock = new MockClient([
        $request::class => MockResponse::make(firewall_sdk_envelope($action), 201),
    ]);
    $response = firewall_sdk_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->resolveEndpoint())
        ->toBe("/api/v1/nodes/7/firewall-rules/{$action}")
        ->and($request->body()->all())
        ->toBe([
            'port' => '443',
            'source' => '192.0.2.0/24',
            'protocol' => 'tcp',
            'label' => 'office web',
        ])
        ->and($response)
        ->toBeInstanceOf(OperationResponse::class)
        ->and($response->kind)->toBe('firewall.'.$action)
        ->and($response->status)->toBe('queued')
        ->and($response->requestId)->toBe(firewall_sdk_request_id())
        ->and($mock->getLastPendingRequest()?->headers()->get('X-Orbit-Request-Id'))
        ->toBe('11111111-1111-4111-8111-111111111111');
})->with([
    'allow' => [AllowFirewallRuleRequest::class, 'allow'],
    'deny' => [DenyFirewallRuleRequest::class, 'deny'],
]);

it('omits firewall fields that the Gateway reads as any', function (): void {
    expect(new AllowFirewallRuleRequest(7, '443')->body()->all())->toBe(['port' => '443'])
        ->and(new RemoveFirewallRuleRequest(7, '443')->body()->all())->toBe(['port' => '443']);
});

it('lists the rules of every Node, or of one Node with Orbit\'s rules and the live comparison', function (): void {
    $node = [
        'node_id' => 7, 'node' => 'app-dev',
        'agent' => ['state' => 'offline', 'since' => '2026-11-05T09:00:00Z'],
        'rules' => [[...firewall_sdk_data('allow', backendStatus: null), 'match' => 'exact']],
        'managed' => [['name' => 'orbit:wireguard-members', 'role' => null, 'action' => 'allow', 'source' => 'any',
            'destination' => '10.44.0.2', 'port' => 'any', 'protocol' => 'any', 'interface' => 'orbit', 'match' => 'missing']],
        'live' => ['backend_status' => 'active', 'observed_at' => '2026-11-05T09:00:00Z', 'possibly_outdated' => true, 'unmatched' => []],
    ];
    $all = new ListFirewallRulesRequest;
    $one = new ListFirewallRulesRequest(7, managed: true, live: true);
    $listMock = new MockClient([
        ListFirewallRulesRequest::class => MockResponse::make([
            'data' => [$node],
            'meta' => ['request_id' => firewall_sdk_request_id()],
        ]),
    ]);
    $listResponse = firewall_sdk_connector($listMock)->send($one)->dto();

    expect($all->getMethod())
        ->toBe(Method::GET)
        ->and($all->resolveEndpoint())
        ->toBe('/api/v1/firewall-rules')
        ->and($all->query()->all())
        ->toBe([])
        ->and($one->query()->all())
        ->toBe(['node' => 7, 'managed' => '1', 'live' => '1'])
        ->and($listResponse)
        ->toBeInstanceOf(FirewallRulesResponse::class)
        ->and($listResponse->nodes)
        ->toHaveCount(1)
        ->and($listResponse->nodes[0]->rules[0]->label)
        ->toBe('office web')
        ->and($listResponse->nodes[0]->rules[0]->match)
        ->toBe('exact')
        ->and($listResponse->nodes[0]->rules[0]->toArray())
        ->not->toHaveKey('name')
        ->and($listResponse->nodes[0]->agent?->label())
        ->toBe('offline since 2026-11-05T09:00:00Z')
        ->and($listResponse->nodes[0]->possiblyOutdated())
        ->toBeTrue()
        ->and($listResponse->nodes[0]->managed[0]['match'])
        ->toBe('missing')
        ->and($listResponse->requestId)
        ->toBe(firewall_sdk_request_id())
        ->and($listMock->getLastPendingRequest()?->headers()->get('X-Orbit-Request-Id'))
        ->toBe('11111111-1111-4111-8111-111111111111');
});

it('leaves out the parts of a Node that the list was not asked for', function (): void {
    $response = FirewallNodeRulesResponse::fromGatewayData(
        ['node_id' => 7, 'node' => 'app-dev', 'rules' => [firewall_sdk_data('allow', backendStatus: null)]],
        firewall_sdk_request_id(),
    );

    expect($response->toArray())->toHaveKeys(['node_id', 'node', 'rules'])->not->toHaveKeys(['managed', 'live', 'agent'])
        ->and($response->toArray()['rules'][0])->not->toHaveKeys(['match', 'request_id']);
});

it('removes one rule by its port, source, and protocol', function (): void {
    $remove = new RemoveFirewallRuleRequest(7, '8000:8100', 'any', 'udp');
    $removeMock = new MockClient([
        RemoveFirewallRuleRequest::class => MockResponse::make(firewall_sdk_envelope('allow', backendStatus: 'absent')),
    ]);
    $removeResponse = firewall_sdk_connector($removeMock)->send($remove)->dto();

    expect($remove->getMethod())
        ->toBe(Method::POST)
        ->and($remove->resolveEndpoint())
        ->toBe('/api/v1/nodes/7/firewall-rules/remove')
        ->and($remove->body()->all())
        ->toBe(['port' => '8000:8100', 'source' => 'any', 'protocol' => 'udp'])
        ->and($removeResponse)
        ->toBeInstanceOf(OperationResponse::class)
        ->and($removeResponse->kind)->toBe('firewall.remove')
        ->and($removeResponse->requestId)->toBe(firewall_sdk_request_id())
        ->and($removeMock->getLastPendingRequest()?->headers()->get('X-Orbit-Request-Id'))
        ->toBe('11111111-1111-4111-8111-111111111111');
});

it('throws a structured 503 failure when the firewall backend is inactive', function (
    string $requestClass,
): void {
    $request = new $requestClass(7, '443', '192.0.2.0/24', 'tcp');
    $mock = new MockClient([
        $request::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'firewall.backend_inactive',
                    'message' => 'UFW is inactive on node [app-dev].',
                    'details' => ['step' => 'status'],
                ],
            ],
            503,
            ['X-Orbit-Request-Id' => firewall_sdk_request_id()],
        ),
    ]);

    try {
        firewall_sdk_connector($mock)->send($request)->dto();
        $this->fail('Expected GatewayApiException.');
    } catch (GatewayApiException $exception) {
        expect($exception->errorCode())
            ->toBe('firewall.backend_inactive')
            ->and($exception->getMessage())
            ->toBe('UFW is inactive on node [app-dev].')
            ->and($exception->details())
            ->toBe(['step' => 'status'])
            ->and($exception->requestId())
            ->toBe(firewall_sdk_request_id());
    }
})->with([
    'allow' => [AllowFirewallRuleRequest::class],
    'deny' => [DenyFirewallRuleRequest::class],
    'remove' => [RemoveFirewallRuleRequest::class],
]);

function firewall_sdk_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector(
        'https://10.44.0.1',
        requestIdResolver: static fn (): string => '11111111-1111-4111-8111-111111111111',
    );
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array<string, mixed> */
function firewall_sdk_envelope(string $action, ?string $backendStatus = 'active'): array
{
    return [
        'data' => ['id' => 42, 'kind' => 'firewall.'.($backendStatus === 'absent' ? 'remove' : $action),
            'target_type' => 'node_firewall', 'target_id' => 7, 'input' => [], 'status' => 'queued', 'sequence' => 1],
        'meta' => ['request_id' => firewall_sdk_request_id()],
    ];
}

/** @return array<string, mixed> */
function firewall_sdk_data(string $action, ?string $backendStatus = 'active'): array
{
    $data = [
        'id' => 11,
        'node_id' => 7,
        'node' => 'app-dev',
        'label' => 'office web',
        'action' => $action,
        'source' => '192.0.2.0/24',
        'protocol' => 'tcp',
        'port' => '443',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ];

    if ($backendStatus !== null) {
        $data['backend_status'] = $backendStatus;
    }

    return $data;
}

function firewall_sdk_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

it('forwards the exact firewall admission retry key', function (): void {
    $allow = new AllowFirewallRuleRequest(7, '65431', idempotencyKey: 'proof-allow');
    $remove = new RemoveFirewallRuleRequest(7, '65431', idempotencyKey: 'proof-remove');
    expect($allow->headers()->get('Idempotency-Key'))->toBe('proof-allow');
    expect($remove->headers()->get('Idempotency-Key'))->toBe('proof-remove');
});

it('reads an absent label as null', function (): void {
    $response = FirewallRuleResponse::fromGatewayData([...firewall_sdk_data('deny'), 'label' => null, 'protocol' => 'any'], firewall_sdk_request_id());
    expect($response->label)->toBeNull()
        ->and($response->toArray())->toMatchArray(['label' => null, 'protocol' => 'any']);
});
