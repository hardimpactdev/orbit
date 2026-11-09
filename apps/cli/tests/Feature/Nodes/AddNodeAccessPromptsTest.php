<?php

declare(strict_types=1);

use App\Commands\Nodes\AddNodeAccessCommand;
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
use Orbit\Sdk\Requests\Nodes\AddNodeAccessRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-access-prompts-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/existing/ca.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

/** @param list<string> $keys @param array<string, mixed> $arguments @return array{int, string} */
function access_add_prompt(array $keys, array $arguments = []): array
{
    $command = new AccessAddPromptsCommand(new AccessAddPromptsTerminal($keys));
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    return PromptContext::preserve(function () use ($tester, $arguments): array {
        Prompt::fallbackWhen(false);

        return [$tester->execute($arguments), $tester->getDisplay()];
    });
}

function access_add_prompt_gateway(bool $empty = false): MockClient
{
    return MockClient::global([
        ...($empty ? [ListNodesRequest::class => MockResponse::make(['data' => []])] : gateway_fixture_mock('nodes/node-list/default')),
        AddNodeAccessRequest::class => MockResponse::make(['data' => [
            'consumer_node' => ['id' => 2, 'name' => 'app-dev'],
            'serving_node' => ['id' => 2, 'name' => 'app-dev'],
            'already_exists' => false,
        ], 'meta' => ['request_id' => 'access-prompt-request']]),
    ]);
}

it('selects omitted access endpoints in consumer then serving order', function (): void {
    $mock = access_add_prompt_gateway();
    [$status, $display] = access_add_prompt([Key::ENTER, Key::ENTER]);
    expect($status)->toBe(0);
    expect(strpos($display, 'Consumer'))->toBeLessThan(strpos($display, 'Serving'));
    $mock->assertSentCount(1, ListNodesRequest::class);
    $mock->assertSentCount(1, AddNodeAccessRequest::class);
    $mock->assertSent(fn ($request): bool => $request instanceof AddNodeAccessRequest && $request->resolveEndpoint() === '/api/v1/nodes/2/access/2');
});

it('selects only the omitted access endpoint', function (array $arguments, string $label, string $endpoint): void {
    $mock = access_add_prompt_gateway();
    [$status, $display] = access_add_prompt([Key::ENTER], $arguments);
    expect($status)->toBe(0)->and($display)->toContain($label);
    $mock->assertSentCount(1, ListNodesRequest::class);
    $mock->assertSentCount(1, AddNodeAccessRequest::class);
    $mock->assertSent(fn ($request): bool => $request instanceof AddNodeAccessRequest && $request->resolveEndpoint() === $endpoint);
})->with([
    [['consumer' => '9'], 'Serving', '/api/v1/nodes/2/access/9'],
    [['serving' => '9'], 'Consumer', '/api/v1/nodes/9/access/2'],
]);

it('sends no grant on cancellation at either selection or an empty list', function (array $keys, bool $empty): void {
    $mock = access_add_prompt_gateway($empty);
    [$status] = access_add_prompt($keys);
    expect($status)->toBe(1);
    $mock->assertNotSent(AddNodeAccessRequest::class);
})->with([
    [[Key::CTRL_C], false], [[Key::ENTER, Key::CTRL_C], false], [[], true],
]);

it('validates every supplied ID before listing prompt choices', function (): void {
    $mock = MockClient::global();
    [$status, $display] = access_add_prompt([], ['serving' => 'bad']);
    expect($status)->toBe(1)->and($display)->toContain('node_access.serving_id_invalid');
    expect($mock->getLastPendingRequest())->toBeNull();
});

class AccessAddPromptsCommand extends AddNodeAccessCommand
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

final class AccessAddPromptsTerminal extends Terminal
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
