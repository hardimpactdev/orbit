<?php

declare(strict_types=1);

use App\Commands\Nodes\AddNodeRoleCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\CommandPrompts;
use App\Support\Console\ConsoleMode;
use App\Support\Console\PromptAborted;
use App\Support\Console\PromptContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Orbit\Sdk\Requests\DatabaseServers\ListDatabaseServersRequest;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-role-prompts-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/existing/ca.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

/** @param list<string> $keys @param array<string, mixed> $arguments @return array{int, string} */
function role_add_prompt(array $keys, array $arguments = []): array
{
    $command = new RoleAddPromptsCommand(new RoleAddPromptsTerminal($keys));
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    return PromptContext::preserve(function () use ($tester, $arguments): array {
        Prompt::fallbackWhen(false);

        return [$tester->execute($arguments), $tester->getDisplay()];
    });
}

/** @param list<array<string, mixed>> $processes @param list<array<string, mixed>> $servers */
function role_add_prompt_gateway(array $processes = [], array $servers = []): MockClient
{
    return MockClient::global([
        ListNodesRequest::class => MockResponse::make(['data' => [['id' => 4, 'name' => 'app-dev']]]),
        ListProcessesRequest::class => MockResponse::make(['data' => $processes]),
        ListDatabaseServersRequest::class => MockResponse::make(['data' => $servers]),
        AddNodeRoleRequest::class => MockResponse::make(['error' => ['code' => 'node_role.test', 'message' => 'Admission reached.']], 422),
    ]);
}

it('selects an omitted role and Node before sending the typed admission request', function (): void {
    $mock = role_add_prompt_gateway();
    [$status, $display] = role_add_prompt([Key::ENTER, Key::ENTER]);

    expect($status)->toBe(1)->and($display)->toContain('Role', 'Node', 'Admission reached.');
    $mock->assertSentCount(1, ListNodesRequest::class);
    $mock->assertSentCount(1, AddNodeRoleRequest::class);
    $mock->assertSent(fn ($request): bool => $request instanceof AddNodeRoleRequest && $request->resolveEndpoint() === '/api/v1/nodes/4/roles' && $request->body()->all() === ['role' => 'gateway', 'converge_existing' => false]);
});

it('selects only the omitted role for an explicit numeric Node', function (): void {
    $mock = role_add_prompt_gateway();
    [$status, $display] = role_add_prompt([Key::ENTER], ['node' => '4']);
    expect($status)->toBe(1)->and($display)->toContain('Role', 'Admission reached.');
    $mock->assertNotSent(ListNodesRequest::class);
    $mock->assertSentCount(1, AddNodeRoleRequest::class);
});

it('selects only the omitted Node for an explicit role', function (): void {
    $mock = role_add_prompt_gateway();
    [$status, $display] = role_add_prompt([Key::ENTER], ['role' => 'app-dev']);
    expect($status)->toBe(1)->and($display)->toContain('Node', 'Admission reached.');
    $mock->assertSentCount(1, ListNodesRequest::class);
    $mock->assertSent(fn ($request): bool => $request instanceof AddNodeRoleRequest && $request->body()->all()['role'] === 'app-dev');
});

it('sends no admission on cancellation or an empty Node list', function (bool $empty): void {
    $mock = $empty ? MockClient::global([ListNodesRequest::class => MockResponse::make(['data' => []])]) : role_add_prompt_gateway();
    [$status, $display] = role_add_prompt($empty ? [Key::ENTER] : [Key::CTRL_C]);
    expect($status)->toBe(1)->and($display)->not->toContain('Admission reached.');
    $mock->assertNotSent(AddNodeRoleRequest::class);
})->with([false, true]);

it('offers supported PostgreSQL storage and excludes unmanaged PostgreSQL 18', function (string $image, bool $managed, bool $selectable): void {
    $mock = role_add_prompt_gateway([
        ['id' => 10, 'name' => 'storage', 'target_type' => 'node', 'target_id' => 8, 'runtime' => 'docker', 'runtime_config' => ['image' => $image]],
        ['id' => 11, 'name' => 'clickhouse', 'target_type' => 'node', 'target_id' => 9, 'runtime' => 'docker', 'runtime_config' => ['image' => 'clickhouse/clickhouse-server:26.8']],
    ], $managed ? [['id' => 2, 'slug' => 'storage', 'node_id' => 8, 'process_id' => 10, 'driver' => 'pgsql', 'tag' => '18', 'port' => 5432, 'status' => 'active', 'databases_count' => 0]] : []);
    [$status, $display] = role_add_prompt([Key::ENTER, Key::ENTER], ['node' => '4', 'role' => 'analytics']);
    expect($status)->toBe(1);
    if ($selectable) {
        expect($display)->toContain('PostgreSQL Process', 'ClickHouse Process', 'Admission reached.');
        $mock->assertSent(fn ($request): bool => $request instanceof AddNodeRoleRequest && $request->body()->all()['postgres_process_id'] === 10 && $request->body()->all()['clickhouse_process_id'] === 11);
    } else {
        expect($display)->toContain('No PostgreSQL Process exists yet.');
        $mock->assertNotSent(AddNodeRoleRequest::class);
    }
})->with([
    'managed PostgreSQL 18' => ['postgres:18.1', true, true],
    'unmanaged PostgreSQL 18' => ['postgres:18.1', false, false],
    'unmanaged PostgreSQL 16' => ['postgres:16.5', false, true],
    'managed PostgreSQL 16 is not a managed 18 process' => ['postgres:16.5', true, false],
]);

it('selects only omitted Analytics storage IDs and leaves explicit IDs to admission', function (array $options, int $prompts): void {
    $mock = role_add_prompt_gateway([
        ['id' => 10, 'name' => 'storage', 'target_type' => 'node', 'target_id' => 8, 'runtime' => 'docker', 'runtime_config' => ['image' => 'postgres:18.1']],
        ['id' => 11, 'name' => 'clickhouse', 'target_type' => 'node', 'target_id' => 9, 'runtime' => 'docker', 'runtime_config' => ['image' => 'clickhouse/clickhouse-server:26.8']],
    ], [['id' => 2, 'slug' => 'storage', 'node_id' => 8, 'process_id' => 10, 'driver' => 'pgsql', 'tag' => '18', 'port' => 5432, 'status' => 'active', 'databases_count' => 0]]);
    [$status] = role_add_prompt(array_fill(0, $prompts, Key::ENTER), ['node' => '4', 'role' => 'analytics', ...$options]);
    expect($status)->toBe(1);
    $mock->assertSentCount(1, AddNodeRoleRequest::class);
    $mock->assertSent(fn ($request): bool => $request instanceof AddNodeRoleRequest && $request->body()->all()['postgres_process_id'] === 10 && $request->body()->all()['clickhouse_process_id'] === 11);
    if (isset($options['--postgres-process'])) {
        $mock->assertNotSent(ListDatabaseServersRequest::class);
    }
    if ($prompts === 0) {
        $mock->assertNotSent(ListProcessesRequest::class);
    }
})->with([
    [['--postgres-process' => '10'], 1],
    [['--clickhouse-process' => '11'], 1],
    [['--postgres-process' => '10', '--clickhouse-process' => '11'], 0],
]);

class RoleAddPromptsCommand extends AddNodeRoleCommand
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

final class RoleAddPromptsTerminal extends Terminal
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
