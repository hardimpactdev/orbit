<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
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

it('rejects missing selectors in non-interactive modes before connector io', function (array $arguments, string $code): void {
    MockClient::global([]);
    $this->artisan('node:role:add', $arguments)->expectsOutputToContain($code)->assertFailed();
    MockClient::getGlobal()->assertNothingSent();
})->with([
    [['--json' => true], 'node_role.role_required'],
    [['--json' => true, 'node' => '4'], 'node_role.role_required'],
    [['--json' => true, 'role' => 'app-dev'], 'node.id_invalid'],
    [['--no-interaction' => true], 'node_role.role_required'],
    [['--no-interaction' => true, 'node' => '4'], 'node_role.role_required'],
]);

it('rejects an invalid node id before connector io', function (string $nodeId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:role:add', ['node' => $nodeId, 'role' => 'app-dev'])
        ->expectsOutputToContain('Node ID must be a positive integer.')
        ->expectsOutputToContain('Code: node.id_invalid')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'zero' => '0',
    'negative' => '-1',
    'recorded name' => 'app-dev',
    'unknown name' => 'operator',
    'overflow' => '999999999999999999999999',
]);

it('refuses Analytics storage options on another role without sending a request', function (string $option): void {
    $mockClient = MockClient::global();

    $this->artisan('node:role:add', [
        'node' => '7', 'role' => 'app-dev', '--converge' => true, '--json' => true, $option => '41',
    ])->expectsOutput(json_encode(['error' => [
        'code' => 'validation.failed',
        'message' => 'Analytics storage options are only valid for the analytics role.',
        'details' => ['field' => $option],
        'request_id' => null,
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with(['--postgres-process', '--clickhouse-process']);

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
        'Postgres overflow' => [['--postgres-process' => '999999999999999999999999', '--clickhouse-process' => '42']],
        'ClickHouse overflow' => [['--postgres-process' => '41', '--clickhouse-process' => '999999999999999999999999']],
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

function node_role_add_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
