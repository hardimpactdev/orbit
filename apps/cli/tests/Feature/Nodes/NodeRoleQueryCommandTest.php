<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ListNodeRolesRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=200');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-node-role-query-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('registers the exact node role list command signature surface', function (): void {
    $command = app(Kernel::class)->all()['node:role:list'] ?? null;

    expect($command)
        ->toBeInstanceOf(SymfonyCommand::class)
        ->and(array_keys($command?->getDefinition()->getArguments() ?? []))
        ->toBe(['node'])
        ->and($command?->getDefinition()->getArgument('node')->getDescription())
        ->toBe('Positive numeric Node ID')
        ->and($command?->getDefinition()->getArgument('node')->isRequired())
        ->toBeFalse()
        ->and(node_role_command_options($command))
        ->toBe(['json' => false]);
});

it('validates locally even without a Gateway profile', function (): void {
    new Filesystem()->deleteDirectory($this->orbitHome);
    $mockClient = MockClient::global();

    expect(Artisan::call('node:role:list', ['node' => 'app-dev', '--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'error' => ['code' => 'node.id_invalid', 'message' => 'Node ID must be a positive integer.', 'request_id' => null],
    ]);
    $mockClient->assertNothingSent();
});

it('retains assignment order and nullable failures in both output forms', function (): void {
    $assignments = [
        ['id' => 4, 'role' => 'app-dev', 'status' => 'active', 'failed_step' => 'converge:check', 'error_code' => 'check.failed'],
        ['id' => 3, 'role' => 'app-prod', 'status' => 'provisioning', 'failed_step' => null, 'error_code' => null],
        ['id' => 2, 'role' => 'database', 'status' => 'failed', 'failed_step' => 'converge:packages', 'error_code' => 'packages.failed'],
        ['id' => 1, 'role' => 'websocket', 'status' => 'removing', 'failed_step' => null, 'error_code' => null],
    ];
    $mockClient = MockClient::global([
        ListNodeRolesRequest::class => MockResponse::make(['data' => $assignments, 'meta' => ['request_id' => node_role_command_request_id()]]),
    ]);
    expect(Artisan::call('node:role:list', ['node' => '7', '--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'assignments' => $assignments, 'request_id' => node_role_command_request_id(),
    ]);

    expect(Artisan::call('node:role:list', ['node' => '7']))->toBe(0);
    $human = Artisan::output();
    $offset = 0;
    foreach ($assignments as $assignment) {
        $position = strpos($human, $assignment['role'], $offset);
        expect($position)->not->toBeFalse();
        $offset = $position + strlen($assignment['role']);
        expect($human)->toContain($assignment['status']);
    }
    expect($human)->toContain('converge:check', 'check.failed', 'converge:packages', 'packages.failed', '—');
    $mockClient->assertSentCount(2);
});

it('lists node roles from the active gateway as JSON', function (): void {
    $assignment = node_role_assignment_payload();
    $mockClient = MockClient::global([
        ListNodeRolesRequest::class => MockResponse::make([
            'data' => [$assignment],
            'meta' => ['request_id' => node_role_command_request_id()],
        ]),
    ]);
    $expected = json_encode([
        'assignments' => [$assignment],
        'request_id' => node_role_command_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect(Artisan::call('node:role:list', ['node' => '7', '--json' => true]))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain($expected);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(ListNodeRolesRequest::class)
        ->and($request?->getMethod())
        ->toBe(Method::GET)
        ->and($request?->resolveEndpoint())
        ->toBe('/api/v1/nodes/7/roles');
});

it('shows a concise node role table with lifecycle and failure columns', function (): void {
    MockClient::global([
        ListNodeRolesRequest::class => MockResponse::make([
            'data' => [
                node_role_assignment_payload(),
                failed_node_role_assignment_payload(),
            ],
            'meta' => ['request_id' => node_role_command_request_id()],
        ]),
    ]);

    expect(Artisan::call('node:role:list', ['node' => '7']))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('ID');
    expect($output)->toContain('ROLE');
    expect($output)->toContain('STATUS');
    expect($output)->toContain('FAILED STEP');
    expect($output)->toContain('ERROR CODE');
    expect(preg_replace('/[ \t]+/', ' ', $output))
        ->toContain('│ 34 │ app-dev │ active │ — │ — │')
        ->toContain('│ 35 │ app-prod │ failed │ converge:packages │ packages.failed │');
    expect($output)->toContain('Request ID: '.node_role_command_request_id());
});

it('lists Ingress lifecycle identity in JSON and human modes without extra settings', function (): void {
    $assignment = [
        'id' => 41,
        'role' => 'ingress',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ];
    MockClient::global([
        ListNodeRolesRequest::class => MockResponse::make([
            'data' => [$assignment],
            'meta' => ['request_id' => node_role_command_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:list', ['node' => '17', '--json' => true])
        ->expectsOutput(json_encode([
            'assignments' => [$assignment],
            'request_id' => node_role_command_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);
    expect(Artisan::call('node:role:list', ['node' => '17']))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('ID');
    expect($output)->toContain('ROLE');
    expect($output)->toContain('STATUS');
    expect($output)->toContain('FAILED STEP');
    expect($output)->toContain('ERROR CODE');
    expect(preg_replace('/[ \t]+/', ' ', $output))
        ->toContain('│ 41 │ ingress │ active │ — │ — │');
    expect($output)->toContain('Request ID: '.node_role_command_request_id());
});

it('shows an empty node role result clearly', function (): void {
    MockClient::global([
        ListNodeRolesRequest::class => MockResponse::make([
            'data' => [],
            'meta' => ['request_id' => node_role_command_request_id()],
        ]),
    ]);

    expect(Artisan::call('node:role:list', ['node' => '7']))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('No roles.');
    expect($output)->toContain('Request ID: '.node_role_command_request_id());
});

it('rejects an invalid node role list node id before connector io', function (string $nodeId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:role:list', ['node' => $nodeId])
        ->expectsOutputToContain('Node ID must be a positive integer. (node.id_invalid)')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'zero' => '0',
    'negative' => '-1',
    'name' => 'app-dev',
    'option-like' => '--not-a-node',
    'fraction' => '1.5',
    'overflow' => '999999999999999999999',
]);

it('refuses missing and invalid input as JSON without any HTTP request', function (?string $node): void {
    $mockClient = MockClient::global();
    $arguments = ['--json' => true];
    if ($node !== null) {
        $arguments['node'] = $node;
    }

    expect(Artisan::call('node:role:list', $arguments))->toBe(1);
    $error = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($error['error']['code'])->toBe('node.id_invalid')
        ->and($error['error']['request_id'])->toBeNull();
    $mockClient->assertNothingSent();
})->with([null, '0', '-1', 'app-dev', '--not-a-node', '1.5', '999999999999999999999']);

it('refuses missing input in noninteractive human mode without HTTP', function (): void {
    $mockClient = MockClient::global();

    expect(Artisan::call('node:role:list', ['--no-interaction' => true]))->toBe(1);
    expect(Artisan::output())->toContain('Node ID must be a positive integer.');
    $mockClient->assertNothingSent();
});

it('prints the request id for node role list gateway API errors', function (): void {
    MockClient::global([
        ListNodeRolesRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'gateway.unavailable',
                    'message' => 'Gateway is unavailable.',
                    'details' => [],
                ],
            ],
            503,
            ['X-Orbit-Request-Id' => node_role_command_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:list', ['node' => '7'])
        ->expectsOutputToContain('Gateway is unavailable.')
        ->expectsOutput('Request ID: '.node_role_command_request_id())
        ->assertExitCode(1);
});

function node_role_command_options(?SymfonyCommand $command): array
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
function node_role_assignment_payload(): array
{
    return [
        'id' => 34,
        'role' => 'app-dev',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ];
}

/** @return array<string, mixed> */
function failed_node_role_assignment_payload(): array
{
    return [
        'id' => 35,
        'role' => 'app-prod',
        'status' => 'failed',
        'failed_step' => 'converge:packages',
        'error_code' => 'packages.failed',
    ];
}

function node_role_command_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
