<?php

declare(strict_types=1);

use App\Commands\Firewall\DenyFirewallRuleCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\CommandPrompts;
use App\Support\Console\ConsoleMode;
use App\Support\Console\PromptAborted;
use App\Support\Console\PromptContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Orbit\Sdk\Requests\Firewall\AllowFirewallRuleRequest;
use Orbit\Sdk\Requests\Firewall\DenyFirewallRuleRequest;
use Orbit\Sdk\Requests\Firewall\ListFirewallRulesRequest;
use Orbit\Sdk\Requests\Firewall\RemoveFirewallRuleRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

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

it('lists the rules of one Node in the order the Gateway returns them', function (): void {
    $mock = firewall_cli_mock([
        ListFirewallRulesRequest::class => MockResponse::make([
            'data' => [firewall_cli_node_entry([
                [...firewall_cli_payload(action: 'allow', backendStatus: null), 'port' => '8000:8100', 'label' => null],
                [...firewall_cli_payload(action: 'deny', backendStatus: null), 'port' => '443', 'protocol' => 'udp'],
                firewall_cli_payload(action: 'allow', backendStatus: null),
            ])],
            'meta' => ['request_id' => firewall_cli_request_id()],
        ]),
    ]);

    expect(Artisan::call('firewall:list', ['--node' => '7']))->toBe(0);
    $output = Artisan::output();
    expect($output)->toMatch('/PORT[\s│]+PROTOCOL[\s│]+SOURCE[\s│]+ACTION[\s│]+LABEL[\s│]+STATUS/u')
        ->not->toContain('NODE')
        ->not->toContain('MATCH')
        ->toMatch('/8000:8100[\s│]+tcp.*—.*443[\s│]+udp[\s│]+any[\s│]+deny.*443[\s│]+tcp[\s│]+192\.0\.2\.0\/24[\s│]+allow[\s│]+office web/su')
        ->toContain('Request ID: '.firewall_cli_request_id());
    $mock->assertSent(fn ($request): bool => $request instanceof ListFirewallRulesRequest
        && $request->resolveEndpoint() === '/api/v1/firewall-rules'
        && $request->query()->all() === ['node' => 7]);
});

it('lists every Node with a Node column when --node is omitted', function (): void {
    $mock = firewall_cli_mock([
        ListFirewallRulesRequest::class => MockResponse::make([
            'data' => [
                firewall_cli_node_entry([firewall_cli_payload(action: 'allow', backendStatus: null)]),
                [...firewall_cli_node_entry([[...firewall_cli_payload(action: 'deny', backendStatus: null), 'node_id' => 8, 'node' => 'app-prod']]), 'node_id' => 8, 'node' => 'app-prod'],
            ],
            'meta' => ['request_id' => firewall_cli_request_id()],
        ]),
    ]);

    expect(Artisan::call('firewall:list'))->toBe(0);
    expect(Artisan::output())->toMatch('/NODE[\s│]+PORT[\s│]+PROTOCOL.*app-dev[\s│]+443.*app-prod[\s│]+9000/su');
    $mock->assertSent(fn ($request): bool => $request instanceof ListFirewallRulesRequest && $request->query()->all() === []);
});

it('adds Orbit rules as locked rows that name their role with --managed', function (): void {
    $mock = firewall_cli_mock([
        ListFirewallRulesRequest::class => MockResponse::make([
            'data' => [[...firewall_cli_node_entry([firewall_cli_payload(action: 'allow', backendStatus: null)]), 'managed' => [
                ['name' => 'orbit:wireguard-members', 'role' => null, 'action' => 'allow', 'source' => '10.44.0.0/24', 'destination' => 'any', 'port' => 'any', 'protocol' => 'any', 'interface' => 'orbit'],
                ['name' => 'orbit:database', 'role' => 'database', 'action' => 'allow', 'source' => 'any', 'destination' => 'any', 'port' => '3306', 'protocol' => 'tcp', 'interface' => null],
            ]]],
            'meta' => ['request_id' => firewall_cli_request_id()],
        ]),
    ]);

    expect(Artisan::call('firewall:list', ['--node' => '7', '--managed' => true]))->toBe(0);
    expect(Artisan::output())->toContain('orbit:wire')->toContain('baseline')->toContain('orbit:data')->toContain('role')->toContain('locked');
    $mock->assertSent(fn ($request): bool => $request instanceof ListFirewallRulesRequest && $request->query()->all() === ['node' => 7, 'managed' => '1']);
});

it('shows the match, the backend and a silent agent with --live', function (): void {
    $mock = firewall_cli_mock([
        ListFirewallRulesRequest::class => MockResponse::make([
            'data' => [
                [...firewall_cli_node_entry([[...firewall_cli_payload(action: 'allow', backendStatus: null), 'match' => 'exact']]),
                    'agent' => ['state' => 'online', 'since' => null],
                    'live' => ['backend_status' => 'active', 'observed_at' => '2026-11-05T10:00:00Z', 'possibly_outdated' => false, 'unmatched' => [
                        ['name' => 'unmanaged', 'comment' => '', 'action' => 'allow', 'source' => 'any', 'destination' => 'any', 'port' => '9999', 'protocol' => 'tcp', 'interface' => null, 'family' => 'v4', 'match' => 'unmanaged'],
                    ]]],
                [...firewall_cli_node_entry([[...firewall_cli_payload(action: 'deny', backendStatus: null), 'node_id' => 8, 'node' => 'app-prod', 'match' => 'exact']]), 'node_id' => 8, 'node' => 'app-prod',
                    'agent' => ['state' => 'offline', 'since' => '2026-11-05T09:00:00Z'],
                    'live' => ['backend_status' => 'active', 'observed_at' => '2026-11-05T09:00:00Z', 'possibly_outdated' => true, 'unmatched' => []]],
            ],
            'meta' => ['request_id' => firewall_cli_request_id()],
        ]),
    ]);

    expect(Artisan::call('firewall:list', ['--live' => true]))->toBe(0);
    $output = Artisan::output();
    expect($output)->toMatch('/MATCH/u')
        ->toContain('app-prod: agent offline since 2026-11-05T09:00:00Z')
        ->not->toContain('app-dev: agent')
        ->toContain('app-dev: UFW active, observed 2026-11-05T10:00:00Z')
        ->toMatch('/app-dev[\s│]+443.*exact/su')
        ->toContain('live on')
        ->toContain('unmanag')
        ->toMatch('/app-prod[\s│]+9000.*exact[\s│]+\([\s│]*possibl/su');
    $mock->assertSent(fn ($request): bool => $request instanceof ListFirewallRulesRequest && $request->query()->all() === ['live' => '1']);
});

it('prints one JSON document with the Nodes and their rules', function (): void {
    firewall_cli_mock([
        ListFirewallRulesRequest::class => MockResponse::make([
            'data' => [firewall_cli_node_entry([firewall_cli_payload(action: 'allow', backendStatus: null)])],
            'meta' => ['request_id' => firewall_cli_request_id()],
        ]),
    ]);

    expect(Artisan::call('firewall:list', ['--json' => true]))->toBe(0);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($json['request_id'])->toBe(firewall_cli_request_id())
        ->and($json['nodes'])->toHaveCount(1)
        ->and($json['nodes'][0]['node_id'])->toBe(7)
        ->and($json['nodes'][0]['rules'][0])->toHaveKeys(['port', 'protocol', 'source', 'action', 'label', 'status', 'failed_step', 'error_code'])
        ->and($json['nodes'][0])->not->toHaveKeys(['managed', 'live']);
});

it('says there are no rules when no Node has one', function (): void {
    firewall_cli_mock([
        ListFirewallRulesRequest::class => MockResponse::make(['data' => [firewall_cli_node_entry([])], 'meta' => ['request_id' => firewall_cli_request_id()]]),
    ]);

    expect(Artisan::call('firewall:list', ['--node' => '7']))->toBe(0);
    expect(Artisan::output())->toContain('No firewall rules.');
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

/**
 * @param  list<array<string, mixed>>  $rules
 * @return array<string, mixed>
 */
function firewall_cli_node_entry(array $rules): array
{
    return ['node_id' => 7, 'node' => 'app-dev', 'rules' => $rules];
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

it('reports a repeat of an applied rule as skipped, not as an error', function (): void {
    $applied = ['data' => [...firewall_cli_operation(), 'result' => ['backend' => 'active', 'skipped' => 'firewall.rule_exists']], 'meta' => ['request_id' => firewall_cli_request_id()]];
    MockClient::global([
        AllowFirewallRuleRequest::class => MockResponse::make($applied, 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make($applied),
    ]);

    expect(Artisan::call('firewall:allow', ['port' => '443', '--node' => '7']))->toBe(0);
    expect(Artisan::output())->toContain('Skipped — already applied (firewall.rule_exists)')->not->toContain('Firewall rule succeeded.');
});

it('refuses a missing Node or port without a request when it cannot prompt', function (string $action, array $arguments, string $code, bool $json): void {
    $mock = MockClient::global();

    expect(Artisan::call('firewall:'.$action, [...$arguments, '--json' => $json, '--no-interaction' => true]))->toBe(1);
    expect(Artisan::output())->toContain($json ? $code : match ($code) {
        'firewall.node_id_invalid' => 'Node ID must be a positive integer.',
        'firewall.port_invalid' => 'Firewall port must be from 1 to 65535 or an ordered range.',
    });
    $mock->assertNothingSent();
})->with(['allow', 'deny'])->with([
    'no Node' => [['port' => '443'], 'firewall.node_id_invalid'],
    'no port' => [['--node' => '7'], 'firewall.port_invalid'],
])->with(['human' => false, 'JSON' => true]);

/** @param list<string> $keys
 * @param  array<string, string>  $arguments
 * @return array{int, string}
 */
function firewall_deny_prompt(array $keys, array $arguments = []): array
{
    $command = new FirewallDenyPromptsCommand(new FirewallDenyPromptsTerminal($keys));
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    return PromptContext::preserve(function () use ($tester, $arguments): array {
        Prompt::fallbackWhen(false);

        return [$tester->execute($arguments), $tester->getDisplay()];
    });
}

function firewall_prompt_nodes(): MockResponse
{
    return MockResponse::make(['data' => [
        ['id' => 1, 'name' => 'mac', 'status' => 'active', 'platform' => 'darwin'],
        ['id' => 2, 'name' => 'inactive', 'status' => 'inactive', 'platform' => 'linux'],
        ['id' => 7, 'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux'],
    ]]);
}

describe('firewall:deny prompts', function (): void {
    it('asks again for an invalid port and selects a Node from a data list before admitting once', function (): void {
        $mock = firewall_cli_mock([
            ListNodesRequest::class => firewall_prompt_nodes(),
            DenyFirewallRuleRequest::class => firewall_cli_response('deny', 'active', 202),
        ]);

        [$status, $display] = firewall_deny_prompt(['abc', Key::ENTER, '8080', Key::ENTER, Key::ENTER]);

        expect($status)->toBe(0)->and($display)
            ->toContain('Port (destination port or range)', 'ordered range', 'Node', 'ID', 'Name', 'app-dev')
            ->not->toMatch('/Platform|Status|mac|inactive/');
        $mock->assertSentCount(1, DenyFirewallRuleRequest::class);
        $mock->assertSent(fn ($request): bool => $request instanceof DenyFirewallRuleRequest
            && $request->resolveEndpoint() === '/api/v1/nodes/7/firewall-rules/deny'
            && $request->body()->all() === ['port' => '8080']);
    });

    it('prompts only for an omitted Node when the port is supplied', function (): void {
        $mock = firewall_cli_mock([
            ListNodesRequest::class => firewall_prompt_nodes(),
            DenyFirewallRuleRequest::class => firewall_cli_response('deny', 'active', 202),
        ]);

        [$status, $display] = firewall_deny_prompt([Key::ENTER], ['port' => '8080']);

        expect($status)->toBe(0)->and($display)
            ->toContain('Node', 'ID', 'Name', 'app-dev')
            ->not->toContain('Port (destination port or range)')
            ->not->toMatch('/Platform|Status|mac|inactive/');
        $mock->assertSentCount(1, DenyFirewallRuleRequest::class);
    });

    it('cancels either required prompt without admitting a rule', function (array $arguments): void {
        $mock = firewall_cli_mock([ListNodesRequest::class => firewall_prompt_nodes()]);

        [$status, $display] = firewall_deny_prompt([Key::CTRL_C], $arguments);

        expect($status)->toBe(1)->and($display)->toContain('input.cancelled');
        $mock->assertNotSent(DenyFirewallRuleRequest::class);
    })->with(['port prompt' => [['--node' => '7']], 'Node prompt' => [['port' => '8080']]]);

    it('refuses an empty eligible Node selection instead of inferring a target', function (): void {
        $mock = firewall_cli_mock([ListNodesRequest::class => MockResponse::make(['data' => []])]);

        [$status, $display] = firewall_deny_prompt([], ['port' => '8080']);

        expect($status)->toBe(1)->and($display)->toContain('No active Linux Node is available.');
        $mock->assertNotSent(DenyFirewallRuleRequest::class);
    });
});

final class FirewallDenyPromptsCommand extends DenyFirewallRuleCommand
{
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
}

final class FirewallDenyPromptsTerminal extends Terminal
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
