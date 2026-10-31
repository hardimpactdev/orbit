<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-node-role-add-'.Str::uuid();
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

it('registers the exact node role add command signature surface', function (): void {
    $command = app(Kernel::class)->all()['node:role:add'] ?? null;

    expect($command)
        ->toBeInstanceOf(SymfonyCommand::class)
        ->and(array_keys($command?->getDefinition()->getArguments() ?? []))
        ->toBe(['node', 'role'])
        ->and(node_role_add_command_options($command))
        ->toBe([
            'converge' => false,
            'idempotency-key' => null,
            'postgres-process' => null,
            'clickhouse-process' => null,
            'json' => false,
        ]);
});

it('rejects an invalid node id before connector io', function (string $nodeId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:role:add', ['node' => $nodeId, 'role' => 'app-dev'])
        ->expectsOutputToContain('Node ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'zero' => '0',
    'negative' => '-1',
]);

it('rejects an unknown node name before the role request', function (): void {
    $mockClient = MockClient::global([
        ListNodesRequest::class => MockResponse::make([
            'data' => [node_role_add_node_payload(id: 7, name: 'app-dev')],
            'meta' => ['request_id' => node_role_add_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:add', ['node' => 'operator', 'role' => 'app-dev'])
        ->expectsOutputToContain('Node [operator] is not registered.')
        ->assertExitCode(1);

    $mockClient->assertNotSent(AddNodeRoleRequest::class);
});

it('rejects an empty role before connector io', function (): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:role:add', ['node' => '7', 'role' => ''])
        ->expectsOutputToContain('Role is required.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
});

it('renders gateway-owned node role add failures through the shared boundary', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'validation.failed',
            'message' => 'Role [gateway] is protected from generic mutation.',
            'details' => [
                'field' => 'role',
                'role' => 'gateway',
            ],
            'request_id' => node_role_add_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    MockClient::global([
        AddNodeRoleRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'validation.failed',
                    'message' => 'Role [gateway] is protected from generic mutation.',
                    'details' => [
                        'field' => 'role',
                        'role' => 'gateway',
                    ],
                ],
            ],
            422,
            ['X-Orbit-Request-Id' => node_role_add_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:add', ['node' => '7', 'role' => 'gateway', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);
});

it('keeps the Node, stage, and Caddy message of a failed Caddy build in json and names them once in human output', function (): void {
    $message = 'The Caddy build for Node [app-prod] failed at stage [validate]: Error: loading certificates: open /etc/caddy/orbit-websocket-cert-current/reverb.pem: no such file or directory';
    $response = static fn (): MockResponse => MockResponse::make(
        [
            'error' => [
                'code' => 'node_role.convergence_failed',
                'message' => $message,
                'details' => [
                    'step' => 'websocket-caddy',
                    'node' => 'app-prod',
                    'stage' => 'validate',
                    'message' => 'Error: loading certificates: open /etc/caddy/orbit-websocket-cert-current/reverb.pem: no such file or directory',
                    'stdout' => 'private-output',
                ],
            ],
        ],
        502,
        ['X-Orbit-Request-Id' => node_role_add_request_id()],
    );
    MockClient::global([AddNodeRoleRequest::class => $response]);

    $this
        ->artisan('node:role:add', ['node' => '7', 'role' => 'websocket', '--converge' => true, '--json' => true])
        ->expectsOutput(json_encode([
            'error' => [
                'code' => 'node_role.convergence_failed',
                'message' => $message,
                'details' => [
                    'step' => 'websocket-caddy',
                    'node' => 'app-prod',
                    'stage' => 'validate',
                    'message' => 'Error: loading certificates: open /etc/caddy/orbit-websocket-cert-current/reverb.pem: no such file or directory',
                ],
                'request_id' => node_role_add_request_id(),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(1);

    MockClient::destroyGlobal();
    MockClient::global([AddNodeRoleRequest::class => $response]);
    $exitCode = Artisan::call('node:role:add', ['node' => '7', 'role' => 'websocket', '--converge' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and(substr_count($output, 'reverb.pem'))->toBe(1)
        ->and($output)->toContain('failed at stage [validate]')
        ->not->toContain('stage:', 'private-output');
});

it('keeps the failed step of a convergence failure without a Caddy build', function (): void {
    MockClient::global([
        AddNodeRoleRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'node_role.convergence_failed',
                    'message' => 'Role [ingress] convergence failed on node [app-prod].',
                    'details' => ['step' => 'converge:host-firewall', 'stderr' => 'private-output'],
                ],
            ],
            502,
            ['X-Orbit-Request-Id' => node_role_add_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:add', ['node' => '7', 'role' => 'ingress', '--converge' => true, '--json' => true])
        ->expectsOutput(json_encode([
            'error' => [
                'code' => 'node_role.convergence_failed',
                'message' => 'Role [ingress] convergence failed on node [app-prod].',
                'details' => ['step' => 'converge:host-firewall'],
                'request_id' => node_role_add_request_id(),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->doesntExpectOutputToContain('private-output')
        ->assertExitCode(1);
});

describe('the analytics role', function (): void {

    it('refuses a missing or malformed storage Process without prompting when it cannot prompt, and sends nothing', function (array $options): void {
        $mockClient = MockClient::global();

        $this
            ->artisan('node:role:add', ['node' => '7', 'role' => 'analytics', '--json' => true, ...$options])
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'both missing' => [[]],
        'ClickHouse missing' => [['--postgres-process' => '41']],
        'not a number' => [['--postgres-process' => 'postgres', '--clickhouse-process' => '42']],
        'zero' => [['--postgres-process' => '41', '--clickhouse-process' => '0']],
    ]);

});

function node_role_add_command_options(?SymfonyCommand $command): array
{
    if (! $command instanceof SymfonyCommand) {
        return [];
    }

    return collect($command->getDefinition()->getOptions())
        ->except([
            'help',
            'silent',
            'quiet',
            'verbose',
            'version',
            'ansi',
            'no-ansi',
            'no-interaction',
            'env',
        ])
        ->map(static fn ($option): mixed => $option->getDefault())
        ->all();
}

/** @return array<string, mixed> */
function added_node_role_payload(): array
{
    return [
        'node_id' => 7,
        'node_name' => 'app-1',
        'role' => 'app-dev',
        'assignment' => [
            'id' => 34,
            'role' => 'app-dev',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ],
        'removed' => false,
    ];
}

/** @return array<string, mixed> */
function ingress_added_node_role_payload(): array
{
    return [
        'node_id' => 17,
        'node_name' => 'ingress-1',
        'role' => 'ingress',
        'assignment' => [
            'id' => 41,
            'role' => 'ingress',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ],
        'removed' => false,
    ];
}

/** @return array<string, mixed> */
function added_node_role_expected_json(): array
{
    return [
        ...added_node_role_payload(),
        'degradation' => null,
        'retained_on_node' => [],
        'follow_up' => null,
        'request_id' => node_role_add_request_id(),
    ];
}

function node_role_add_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

/** @return array<string, mixed> */
function node_role_add_node_payload(int $id, string $name): array
{
    return [
        'id' => $id,
        'name' => $name,
        'status' => 'active',
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => null,
        'public_ssh_host' => '203.0.113.7',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.7',
        'wireguard_public_key' => 'key',
        'wireguard_endpoint_override' => null,
        'dns_server_override' => null,
        'ssh_host_fingerprint' => null,
        'failed_step' => null,
        'error_code' => null,
        'roles' => [],
    ];
}
