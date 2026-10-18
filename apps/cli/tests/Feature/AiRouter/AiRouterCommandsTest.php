<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AiRouter\DisableAiRouterAccountRequest;
use Orbit\Sdk\Requests\AiRouter\ListAiRouterModelsRequest;
use Orbit\Sdk\Requests\AiRouter\ListAiRouterProvidersRequest;
use Orbit\Sdk\Requests\AiRouter\SetupAiRouterRequest;
use Orbit\Sdk\Requests\AiRouter\ShowAiRouterProviderRequest;
use Orbit\Sdk\Requests\AiRouter\ShowAiRouterStatusRequest;
use Orbit\Sdk\Requests\AiRouter\TeardownAiRouterRequest;
use Orbit\Sdk\Requests\Extensions\ListExtensionsRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->previousColumns = getenv('COLUMNS');
    $this->callerOrbitHome = config('orbit.home');
    putenv('COLUMNS=200');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-ai-router-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
    config()->set('orbit.home', $this->callerOrbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    if ($this->previousColumns === false) {
        putenv('COLUMNS');
    } else {
        putenv('COLUMNS='.$this->previousColumns);
    }
});

it('sets up the fleet collector from a key file and never prints the key', function (): void {
    if (! is_dir($this->orbitHome)) {
        mkdir($this->orbitHome, 0700, true);
    }
    $keyFile = $this->orbitHome.'/management.key';
    file_put_contents($keyFile, "management-key\n");
    chmod($keyFile, 0600);

    $mock = MockClient::global([
        ListExtensionsRequest::class => ai_router_cli_extensions_response(),
        ListNodesRequest::class => ai_router_cli_nodes_response(),
        SetupAiRouterRequest::class => ai_router_cli_status_response(),
    ]);

    [$exit, $output] = ai_router_cli_display('ai-router:setup', [
        '--node' => 'beast',
        '--cache-connection' => 'valkey',
        '--cliproxy-url' => 'http://127.0.0.1:8317',
        '--cliproxy-management-key-file' => $keyFile,
        '--json' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(json_decode($output, true)['enabled'])->toBeTrue()
        ->and($output)->not->toContain('management-key')
        ->and($mock->getLastPendingRequest()->body()->all()['cliproxy_management_key'])->toBe('management-key');
});

it('refuses a management key on the command line by requiring a file', function (): void {
    MockClient::global([ListExtensionsRequest::class => ai_router_cli_extensions_response()]);

    [$exit, $output] = ai_router_cli_display('ai-router:setup', [
        '--node' => '4',
        '--cache-connection' => 'valkey',
        '--cliproxy-url' => 'http://127.0.0.1:8317',
        '--json' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('ai_router.key_required')
        ->and($output)->not->toContain('cliproxy_management_key');
});

it('lists provider windows from the snapshot without Primary or Secondary labels', function (): void {
    MockClient::global([
        ListExtensionsRequest::class => ai_router_cli_extensions_response(),
        ListAiRouterProvidersRequest::class => MockResponse::make([
            'data' => [ai_router_cli_provider_payload()],
            'meta' => ['request_id' => ai_router_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = ai_router_cli_display('ai-router:provider:list');

    expect($exit)->toBe(0)
        ->and($output)->toContain('codex', '7d', '5h')
        ->and($output)->not->toContain('Primary', 'Secondary');
});

it('lists models from the snapshot', function (): void {
    MockClient::global([
        ListExtensionsRequest::class => ai_router_cli_extensions_response(),
        ListAiRouterModelsRequest::class => MockResponse::make([
            'data' => [
                ['id' => 'gpt-5.6-luna', 'provider' => 'codex'],
                ['id' => 'claude-opus', 'provider' => 'claude'],
            ],
            'meta' => ['request_id' => ai_router_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = ai_router_cli_display('ai-router:model:list');

    expect($exit)->toBe(0)
        ->and($output)->toContain('gpt-5.6-luna', 'codex', 'claude-opus', 'claude')
        ->and($output)->not->toContain('display_name');
});

it('returns an empty model list as json', function (): void {
    MockClient::global([
        ListExtensionsRequest::class => ai_router_cli_extensions_response(),
        ListAiRouterModelsRequest::class => MockResponse::make([
            'data' => [],
            'meta' => ['request_id' => ai_router_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = ai_router_cli_display('ai-router:model:list', ['--json' => true]);
    $decoded = json_decode($output, true);

    expect($exit)->toBe(0)
        ->and($decoded['models'])->toBe([])
        ->and($decoded['request_id'])->toBe(ai_router_cli_request_id());
});

it('shows one provider and omits a window the snapshot did not return', function (): void {
    MockClient::global([
        ListExtensionsRequest::class => ai_router_cli_extensions_response(),
        ShowAiRouterProviderRequest::class => MockResponse::make([
            'data' => ai_router_cli_provider_payload(windows: [
                ['label' => '7d', 'used_percent' => 40.0, 'remaining_percent' => 60.0, 'resets_at' => '2026-09-27T00:00:00Z'],
            ]),
            'meta' => ['request_id' => ai_router_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = ai_router_cli_display('ai-router:provider:show', ['provider' => 'codex']);

    expect($exit)->toBe(0)
        ->and($output)->toContain('plus.json', '7d 60% remaining')
        ->and($output)->not->toContain('5h', 'Primary', 'Secondary', ' 0% remaining');
});

it('updates an account from cache after the status patch', function (): void {
    $mock = MockClient::global([
        ListExtensionsRequest::class => ai_router_cli_extensions_response(),
        DisableAiRouterAccountRequest::class => MockResponse::make([
            'data' => [
                ...ai_router_cli_account_payload(),
                'disabled' => true,
            ],
            'meta' => ['request_id' => ai_router_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = ai_router_cli_display('ai-router:account:disable', [
        'account' => 'plus.json',
        '--json' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(json_decode($output, true)['disabled'])->toBeTrue()
        ->and($mock->getLastPendingRequest()->getMethod()->value)->toBe('POST');
});

it('tears down the fleet collector with explicit consent', function (): void {
    $mock = MockClient::global([
        ListExtensionsRequest::class => ai_router_cli_extensions_response(),
        TeardownAiRouterRequest::class => ai_router_cli_status_response(payload: [
            'enabled' => false,
            'collected_at' => null,
        ]),
    ]);

    [$exit, $output] = ai_router_cli_display('ai-router:teardown', ['--yes' => true, '--json' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode($output, true)['enabled'])->toBeFalse()
        ->and($mock->getLastPendingRequest())->not->toBeNull();
});

it('refuses teardown without --yes when it cannot prompt', function (array $options, string $expected): void {
    $mock = MockClient::global();

    [$exit, $output] = ai_router_cli_display('ai-router:teardown', $options);

    expect($exit)->toBe(1)
        ->and($output)->toContain($expected)
        ->and($mock->getLastPendingRequest())->toBeNull();
})->with([
    'json' => [['--json' => true], '"code":"input.confirmation_required"'],
    'noninteractive human' => [[], 'Non-interactive AiRouter teardown requires --yes.'],
]);

it('shows fleet status including a null collected_at', function (): void {
    MockClient::global([
        ListExtensionsRequest::class => ai_router_cli_extensions_response(),
        ShowAiRouterStatusRequest::class => ai_router_cli_status_response(payload: [
            'collected_at' => null,
        ]),
    ]);

    [$exit, $output] = ai_router_cli_display('ai-router:status', ['--json' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode($output, true)['collected_at'])->toBeNull()
        ->and(json_decode($output, true)['hostname'])->toBe('collector.ai-router.orbit');
});

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function ai_router_cli_display(string $command, array $arguments = []): array
{
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    return [$tester->execute($arguments, ['interactive' => false]), $tester->getDisplay(true)];
}

function ai_router_cli_extensions_response(): MockResponse
{
    return MockResponse::make([
        'data' => ['tasks' => true, 'ai-router' => true],
        'meta' => ['request_id' => ai_router_cli_request_id()],
    ]);
}

function ai_router_cli_nodes_response(): MockResponse
{
    return MockResponse::make([
        'data' => [[
            'id' => 4,
            'name' => 'beast',
            'status' => 'active',
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => null,
            'public_ssh_host' => '203.0.113.7',
            'public_ssh_port' => 22,
            'user' => 'nckrtl',
            'wireguard_ip' => '10.44.0.7',
            'wireguard_public_key' => 'key',
            'wireguard_endpoint_override' => null,
            'dns_server_override' => null,
            'ssh_host_fingerprint' => null,
            'failed_step' => null,
            'error_code' => null,
            'roles' => [],
        ]],
        'meta' => ['request_id' => ai_router_cli_request_id()],
    ]);
}

/** @param array<string, mixed> $payload */
function ai_router_cli_status_response(array $payload = []): MockResponse
{
    return MockResponse::make([
        'data' => [
            'enabled' => true,
            'hostname' => 'collector.ai-router.orbit',
            'node_id' => 4,
            'cache_connection' => 'valkey',
            'collected_at' => '2026-09-20T12:00:00Z',
            ...$payload,
        ],
        'meta' => ['request_id' => ai_router_cli_request_id()],
    ]);
}

/**
 * @param  list<array<string, mixed>>|null  $windows
 * @return array<string, mixed>
 */
function ai_router_cli_provider_payload(?array $windows = null): array
{
    $windows ??= [
        ['label' => '7d', 'used_percent' => 40.0, 'remaining_percent' => 60.0, 'resets_at' => '2026-09-27T00:00:00Z'],
        ['label' => '5h', 'used_percent' => 10.0, 'remaining_percent' => 90.0, 'resets_at' => null],
    ];

    return [
        'provider' => 'codex',
        'windows' => $windows,
        'accounts' => [ai_router_cli_account_payload($windows)],
    ];
}

/**
 * @param  list<array<string, mixed>>|null  $windows
 * @return array<string, mixed>
 */
function ai_router_cli_account_payload(?array $windows = null): array
{
    return [
        'id' => 'plus.json',
        'provider' => 'codex',
        'label' => 'plus',
        'disabled' => false,
        'status' => 'ok',
        'windows' => $windows ?? [
            ['label' => '7d', 'used_percent' => 40.0, 'remaining_percent' => 60.0, 'resets_at' => '2026-09-27T00:00:00Z'],
            ['label' => '5h', 'used_percent' => 10.0, 'remaining_percent' => 90.0, 'resets_at' => null],
        ],
        'error' => null,
    ];
}

function ai_router_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
