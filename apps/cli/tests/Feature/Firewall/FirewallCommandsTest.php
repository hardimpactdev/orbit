<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Firewall\AllowFirewallRuleRequest;
use Orbit\Sdk\Requests\Firewall\DenyFirewallRuleRequest;
use Orbit\Sdk\Requests\Firewall\ListFirewallRulesRequest;
use Orbit\Sdk\Requests\Firewall\RemoveFirewallRuleRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-firewall-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('sends normalized allow input through the active gateway as JSON', function (): void {
    $mock = firewall_cli_mock([
        AllowFirewallRuleRequest::class => firewall_cli_response(action: 'allow', backendStatus: 'active', status: 201),
    ]);

    $this
        ->artisan('firewall:allow', [
            'port' => '443',
            '--node' => '7',
            '--from' => '192.0.2.0/24',
            '--protocol' => 'tcp',
            '--label' => 'office web',
            '--json' => true,
        ])
        ->expectsOutput(firewall_cli_json(action: 'allow', backendStatus: 'active'))
        ->assertExitCode(0);

    $mock->assertSent(fn ($request): bool => $request instanceof AllowFirewallRuleRequest
        && $request->body()->all() === ['port' => '443', 'source' => '192.0.2.0/24', 'protocol' => 'tcp', 'label' => 'office web']);
});

it('omits an absent source, protocol, and label so the Gateway reads any', function (
    string $command,
    string $requestClass,
): void {
    $mock = firewall_cli_mock([
        $requestClass => firewall_cli_response(
            action: $command === 'firewall:allow' ? 'allow' : 'deny',
            backendStatus: 'active',
            status: 201,
        ),
    ]);

    $this
        ->artisan($command, [
            'port' => '443',
            '--node' => '7',
        ])
        ->assertExitCode(0);

    $mock->assertSent(fn ($request): bool => $request instanceof $requestClass && $request->body()->all() === ['port' => '443']);
})->with([
    'allow' => ['firewall:allow', AllowFirewallRuleRequest::class],
    'deny' => ['firewall:deny', DenyFirewallRuleRequest::class],
]);

it('reports inactive UFW as a structured gateway failure', function (
    string $command,
    string $requestClass,
    array $arguments,
): void {
    $mock = firewall_cli_mock([
        $requestClass => firewall_cli_backend_inactive_response(),
    ]);

    $this
        ->artisan($command, $arguments)
        ->expectsOutputToContain('The firewall backend is inactive.')
        ->expectsOutput('Request ID: '.firewall_cli_request_id())
        ->doesntExpectOutputToContain('stored')
        ->doesntExpectOutputToContain('kept')
        ->assertExitCode(1);

    expect($mock->getLastRequest())
        ->toBeInstanceOf($requestClass)
        ->and($mock->getRecordedResponses())
        ->toHaveCount(1);
})->with([
    'allow' => [
        'firewall:allow',
        AllowFirewallRuleRequest::class,
        ['port' => '443', '--node' => '7'],
    ],
    'deny' => [
        'firewall:deny',
        DenyFirewallRuleRequest::class,
        ['port' => '9000', '--node' => '7'],
    ],
    'remove' => [
        'firewall:remove',
        RemoveFirewallRuleRequest::class,
        ['--yes' => true, 'port' => '443', '--node' => '7'],
    ],
]);

it('reports inactive UFW with the exact safe JSON error envelope', function (
    string $command,
    string $requestClass,
    array $arguments,
): void {
    $mock = firewall_cli_mock([
        $requestClass => firewall_cli_backend_inactive_response(),
    ]);
    $expected = json_encode([
        'error' => [
            'code' => 'firewall.backend_inactive',
            'message' => 'The firewall backend is inactive.',
            'request_id' => firewall_cli_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan($command, [...$arguments, '--json' => true])
        ->expectsOutput($expected)
        ->doesntExpectOutputToContain('backend_status')
        ->doesntExpectOutputToContain('stored')
        ->doesntExpectOutputToContain('kept')
        ->assertExitCode(1);

    expect($mock->getLastRequest())
        ->toBeInstanceOf($requestClass)
        ->and($mock->getRecordedResponses())
        ->toHaveCount(1);
})->with([
    'allow JSON' => [
        'firewall:allow',
        AllowFirewallRuleRequest::class,
        ['port' => '443', '--node' => '7'],
    ],
    'deny JSON' => [
        'firewall:deny',
        DenyFirewallRuleRequest::class,
        ['port' => '9000', '--node' => '7'],
    ],
    'remove JSON' => [
        'firewall:remove',
        RemoveFirewallRuleRequest::class,
        ['--yes' => true, 'port' => '443', '--node' => '7'],
    ],
]);

it('lists rules by port, protocol, and source with their label', function (): void {
    firewall_cli_mock([
        ListFirewallRulesRequest::class => MockResponse::make([
            'data' => [
                [...firewall_cli_payload(action: 'allow', backendStatus: null), 'port' => '8000:8100', 'label' => null],
                [...firewall_cli_payload(action: 'deny', backendStatus: null), 'port' => '443', 'protocol' => 'udp'],
                firewall_cli_payload(action: 'allow', backendStatus: null),
            ],
            'meta' => ['request_id' => firewall_cli_request_id()],
        ]),
    ]);

    expect(Artisan::call('firewall:list', ['--node' => '7']))->toBe(0);
    $output = Artisan::output();
    expect($output)->toMatch('/PORT[\s│]+PROTOCOL[\s│]+SOURCE[\s│]+ACTION[\s│]+LABEL[\s│]+STATUS/u')
        ->not->toContain('NAME')
        ->toMatch('/443[\s│]+tcp[\s│]+192\.0\.2\.0\/24[\s│]+allow[\s│]+office web.*443[\s│]+udp[\s│]+any[\s│]+deny.*8000:8100/su')
        ->toContain('Request ID: '.firewall_cli_request_id());
});

it('removes the one rule that matches the port, source, and protocol', function (): void {
    $mock = firewall_cli_mock([
        RemoveFirewallRuleRequest::class => firewall_cli_response(action: 'allow', backendStatus: 'absent'),
    ]);

    $this
        ->artisan('firewall:remove', [
            '--yes' => true,
            'port' => '443',
            '--node' => '7',
            '--from' => 'any',
            '--protocol' => 'tcp',
        ])
        ->expectsOutputToContain('succeeded')
        ->expectsOutput('Request ID: '.firewall_cli_request_id())
        ->assertExitCode(0);

    $mock->assertSent(fn ($request): bool => $request instanceof RemoveFirewallRuleRequest
        && $request->resolveEndpoint() === '/api/v1/nodes/7/firewall-rules/remove'
        && $request->body()->all() === ['port' => '443', 'source' => 'any', 'protocol' => 'tcp']);
});

it('renders every rule that an ambiguous removal matched', function (bool $json): void {
    firewall_cli_mock([
        RemoveFirewallRuleRequest::class => MockResponse::make(['error' => [
            'code' => 'firewall.rule_ambiguous',
            'message' => 'More than one firewall rule matches.',
            'details' => ['matches' => [
                ['port' => '443', 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'label' => 'web'],
                ['port' => '443', 'protocol' => 'udp', 'source' => 'any', 'action' => 'deny', 'label' => null],
            ]],
        ]], 409, ['X-Orbit-Request-Id' => firewall_cli_request_id()]),
    ]);
    $matches = ['443/tcp from any: allow (web)', '443/udp from any: deny'];

    $command = $this->artisan('firewall:remove', ['port' => '443', '--node' => '7', '--yes' => true, '--json' => $json]);
    $json
        ? $command->expectsOutput(json_encode(['error' => ['code' => 'firewall.rule_ambiguous', 'message' => 'More than one firewall rule matches.',
            'details' => ['matches' => $matches], 'request_id' => firewall_cli_request_id()]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        : $command->expectsOutputToContain('More than one firewall rule matches.')->expectsOutputToContain($matches[0])->expectsOutputToContain($matches[1]);
    $command->assertExitCode(1);
})->with(['human' => false, 'JSON' => true]);

it('rejects invalid local input before making a gateway request', function (
    string $command,
    array $arguments,
    string $message,
): void {
    $mock = MockClient::global();

    $this
        ->artisan($command, $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'node ID' => [
        'firewall:list',
        ['--node' => 'app-dev'],
        'Node ID must be a positive integer.',
    ],
    'protocol' => [
        'firewall:allow',
        ['port' => '443', '--node' => '7', '--protocol' => 'sctp'],
        'Firewall protocol must be tcp or udp.',
    ],
    'source' => [
        'firewall:allow',
        ['port' => '443', '--node' => '7', '--from' => 'example.test'],
        'Firewall source must be any or a valid IPv4 or IPv6 address or CIDR.',
    ],
    'port range order' => [
        'firewall:deny',
        ['port' => '9000:8000', '--node' => '7'],
        'Firewall port must be from 1 to 65535 or an ordered range.',
    ],
    'service name' => [
        'firewall:remove',
        ['port' => 'https', '--node' => '7', '--yes' => true],
        'Firewall port must be from 1 to 65535 or an ordered range.',
    ],
    'port above range' => [
        'firewall:allow',
        ['port' => '65536', '--node' => '7', '--from' => '10.44.0.6'],
        'Firewall port must be from 1 to 65535 or an ordered range.',
    ],
]);

it('shows the gateway admission refusal and request ID', function (string $command, string $code, int $status, string $message): void {
    firewall_cli_mock([
        ($command === 'firewall:deny' ? DenyFirewallRuleRequest::class : AllowFirewallRuleRequest::class) => MockResponse::make(
            ['error' => ['code' => $code, 'message' => $message, 'details' => []]],
            $status,
            ['X-Orbit-Request-Id' => firewall_cli_request_id()],
        ),
    ]);

    $this
        ->artisan($command, ['port' => '22', '--node' => '7', '--protocol' => 'tcp', '--json' => true])
        ->expectsOutput(json_encode(['error' => ['code' => $code, 'message' => $message, 'request_id' => firewall_cli_request_id()]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(1);
})->with([
    'conflict' => ['firewall:deny', 'firewall.rule_conflict', 409, 'An allow rule has the same port, protocol, and source.'],
    'exists' => ['firewall:allow', 'firewall.rule_exists', 409, 'This firewall rule exists.'],
    'overlap' => ['firewall:allow', 'firewall.rule_overlap', 409, 'A rule for any protocol overlaps this rule.'],
    'protected port' => ['firewall:deny', 'firewall.protected_port', 422, 'Port 22 is protected.'],
]);

function firewall_cli_response(string $action, ?string $backendStatus, int $status = 200): MockResponse
{
    return MockResponse::make([
        'data' => firewall_cli_operation($action, $backendStatus),
        'meta' => ['request_id' => firewall_cli_request_id()],
    ], $status);
}

function firewall_cli_backend_inactive_response(): MockResponse
{
    return MockResponse::make(
        [
            'error' => [
                'code' => 'firewall.backend_inactive',
                'message' => 'The firewall backend is inactive.',
                'details' => ['backend_status' => 'inactive'],
            ],
        ],
        503,
        ['X-Orbit-Request-Id' => firewall_cli_request_id()],
    );
}

/** @return array<string, mixed> */
function firewall_cli_payload(string $action, ?string $backendStatus): array
{
    return [
        'id' => 11,
        'node_id' => 7,
        'node' => 'app-dev',
        'label' => $action === 'deny' ? null : 'office web',
        'action' => $action,
        'source' => $action === 'deny' ? 'any' : '192.0.2.0/24',
        'protocol' => 'tcp',
        'port' => $action === 'deny' ? '9000' : '443',
        'status' => 'active',
        'backend_status' => $backendStatus,
        'failed_step' => null,
        'error_code' => null,
    ];
}

function firewall_cli_operation(string $action = 'allow', ?string $backendStatus = 'active'): array
{
    return ['id' => 42, 'kind' => 'firewall.'.($backendStatus === 'absent' ? 'remove' : $action),
        'target_type' => 'node_firewall', 'target_id' => 7, 'input' => ['port' => '443'],
        'status' => 'succeeded', 'sequence' => 5, 'result' => ['backend' => $backendStatus]];
}

function firewall_cli_mock(array $responses): MockClient
{
    $last = array_key_exists(RemoveFirewallRuleRequest::class, $responses) ? 'absent' : 'active';
    $action = array_key_exists(DenyFirewallRuleRequest::class, $responses) ? 'deny' : 'allow';

    return MockClient::global([...$responses,
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => firewall_cli_response($action, $last),
    ]);
}

function firewall_cli_json(string $action, ?string $backendStatus): string
{
    return json_encode(OperationResponse::fromGatewayData(firewall_cli_operation($action, $backendStatus), firewall_cli_request_id())->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function firewall_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

it('follows a queued firewall Operation to its final JSON receipt and forwards the retry key', function (): void {
    $mock = MockClient::global([
        AllowFirewallRuleRequest::class => MockResponse::make([
            'data' => [...firewall_cli_operation(), 'status' => 'queued', 'result' => null],
            'meta' => ['request_id' => firewall_cli_request_id()],
        ], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => firewall_cli_response('allow', 'active'),
    ]);
    $this->artisan('firewall:allow', ['port' => '443', '--node' => '7', '--idempotency-key' => 'allow-7', '--json' => true])
        ->expectsOutput(firewall_cli_json('allow', 'active'))->assertExitCode(0);
    $mock->assertSent(fn ($request): bool => $request instanceof AllowFirewallRuleRequest && $request->headers()->get('Idempotency-Key') === 'allow-7');
    $mock->assertSent(fn ($request): bool => $request instanceof ShowOperationRequest);
});
