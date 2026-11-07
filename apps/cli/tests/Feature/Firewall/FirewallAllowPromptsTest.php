<?php

declare(strict_types=1);

use App\Commands\Firewall\AllowFirewallRuleCommand;
use App\Commands\Firewall\StoreFirewallRuleCommand;
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
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Firewall\AllowFirewallRuleRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-firewall-prompts-'.Str::uuid();
    config(['orbit.home' => $this->orbitHome]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.test', '/tmp/root.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('asks for a missing allow port before sending the request', function (): void {
    $mock = firewall_allow_prompt_mock();
    [$status, $display] = firewall_allow_prompt_run(['--node' => '7'], ['5432', Key::ENTER]);

    expect($status)->toBe(0)->and($display)->toContain('Destination port or range');
    $mock->assertSent(fn ($request): bool => $request instanceof AllowFirewallRuleRequest
        && $request->resolveEndpoint() === '/api/v1/nodes/7/firewall-rules/allow'
        && $request->body()->all() === ['port' => '5432']);
    $mock->assertNotSent(ListNodesRequest::class);
});

it('explains an invalid prompted allow port and asks again before admission', function (): void {
    $mock = firewall_allow_prompt_mock();
    [$status, $display] = firewall_allow_prompt_run(['--node' => '7'], ['abc', Key::ENTER, '8000:8100', Key::ENTER]);

    expect($status)->toBe(0)->and($display)->toContain('Firewall port must be from 1 to 65535 or an ordered range.');
    expect(substr_count($display, 'Destination port or range'))->toBeGreaterThanOrEqual(2);
    $mock->assertSent(fn ($request): bool => $request instanceof AllowFirewallRuleRequest
        && $request->body()->all() === ['port' => '8000:8100']);
    $mock->assertNotSent(ListNodesRequest::class);
    $mock->assertSentCount(2);
});

it('lists only active Linux Nodes with ID and Name and sends the selected allow target', function (array $keys): void {
    $mock = firewall_allow_prompt_mock([
        ['id' => 1, 'name' => 'inactive-linux', 'status' => 'inactive', 'platform' => 'linux'],
        ['id' => 2, 'name' => 'active-mac', 'status' => 'active', 'platform' => 'macos'],
        ['id' => 3, 'name' => 'active-ipad', 'status' => 'active', 'platform' => 'ipados'],
        ['id' => 4, 'name' => 'unknown-platform', 'status' => 'active'],
        ['id' => 17, 'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux'],
        ['id' => 23, 'name' => 'app-prod', 'status' => 'active', 'platform' => 'linux'],
    ]);
    [$status, $display] = firewall_allow_prompt_run(['port' => '5432'], $keys);

    expect($status)->toBe(0)->and($display)
        ->toContain('Node', 'ID', 'Name', '17', 'app-dev', '23', 'app-prod', 'Press / to search')
        ->not->toContain('inactive-linux', 'active-mac', 'active-ipad', 'unknown-platform');
    $mock->assertSent(fn ($request): bool => $request instanceof AllowFirewallRuleRequest
        && $request->resolveEndpoint() === '/api/v1/nodes/23/firewall-rules/allow'
        && $request->body()->all() === ['port' => '5432']);
    $mock->assertSent(ListNodesRequest::class);
    $mock->assertSentCount(3);
})->with([
    'arrow selection' => [[Key::DOWN, Key::ENTER]],
    'search selection' => [['/', 'prod', Key::ENTER, Key::ENTER]],
]);

it('cancels the allow port prompt on Ctrl-C without a request', function (): void {
    $mock = firewall_allow_prompt_mock();
    [$status, $display, $failure] = firewall_allow_prompt_run([], [Key::CTRL_C]);

    expect($status)->toBe(1)->and($failure['code'])->toBe('input.cancelled')
        ->and($display)->toContain('Destination port or range', 'input.cancelled');
    $mock->assertNothingSent();
});

it('cancels the allow Node prompt on Ctrl-C without an allow request', function (): void {
    $mock = firewall_allow_prompt_mock([
        ['id' => 7, 'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux'],
    ]);
    [$status, $display, $failure] = firewall_allow_prompt_run(['port' => '5432'], [Key::CTRL_C]);

    expect($status)->toBe(1)->and($failure['code'])->toBe('input.cancelled')
        ->and($display)->toContain('Node', 'input.cancelled');
    $mock->assertNotSent(AllowFirewallRuleRequest::class);
    $mock->assertSent(ListNodesRequest::class);
    $mock->assertSentCount(1);
});

it('refuses the allow Node prompt when no active Linux Node is available', function (array $nodes): void {
    $mock = firewall_allow_prompt_mock($nodes);
    [$status, $display, $failure] = firewall_allow_prompt_run(['port' => '5432'], []);

    expect($status)->toBe(1)->and($failure['code'])->toBe('firewall.node_id_invalid')
        ->and($display)->toContain('No active Linux Node is available.')->not->toContain('Press / to search');
    $mock->assertNotSent(AllowFirewallRuleRequest::class);
    $mock->assertSent(ListNodesRequest::class);
    $mock->assertSentCount(1);
})->with([
    'empty registry' => [[]],
    'only ineligible Nodes' => [[
        ['id' => 1, 'name' => 'inactive-linux', 'status' => 'inactive', 'platform' => 'linux'],
        ['id' => 2, 'name' => 'active-mac', 'status' => 'active', 'platform' => 'macos'],
    ]],
]);

/** @param list<array<string, mixed>> $nodes */
function firewall_allow_prompt_mock(array $nodes = []): MockClient
{
    return MockClient::global([
        ListNodesRequest::class => MockResponse::make(['data' => $nodes]),
        AllowFirewallRuleRequest::class => MockResponse::make(['data' => [
            'id' => 42, 'kind' => 'firewall.allow', 'target_type' => 'node_firewall', 'target_id' => 7,
            'status' => 'succeeded', 'sequence' => 1, 'result' => ['backend' => 'active'],
        ]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
    ]);
}

/**
 * @param  array<string, string>  $arguments
 * @param  list<string>  $keys
 * @return array{int, string, array{code?: string}}
 */
function firewall_allow_prompt_run(array $arguments, array $keys): array
{
    $command = new FirewallAllowPromptFixtureCommand(new FirewallAllowPromptFixtureTerminal($keys));
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    return PromptContext::preserve(function () use ($command, $tester, $arguments): array {
        Prompt::fallbackWhen(false);

        return [$tester->execute($arguments), $tester->getDisplay(), $command->failure];
    });
}

final class FirewallAllowPromptFixtureCommand extends StoreFirewallRuleCommand
{
    protected $signature = 'firewall:allow-prompt-fixture';

    /** @var array{code?: string} */
    public array $failure = [];

    public function __construct(private readonly Terminal $terminal)
    {
        parent::__construct();
        $this->setDefinition(new AllowFirewallRuleCommand()->getNativeDefinition());
    }

    protected function promptsForMissingInput(): bool
    {
        return true;
    }

    protected function request(int $nodeId, string $port, ?string $source, ?string $protocol): GatewayRequest
    {
        return new AllowFirewallRuleRequest($nodeId, $port, $source, $protocol, $this->stringOption('label'), $this->stringOption('idempotency-key'));
    }

    protected function consoleMode(?OutputInterface $output = null): ConsoleMode
    {
        return new ConsoleMode(false, true, false, false, 100);
    }

    protected function commandPrompts(): CommandPrompts
    {
        return new CommandPrompts($this->consoleMode(), $this->output, $this->terminal);
    }

    protected function renderGatewayFailure(string $code, string $message, ?string $requestId = null, ?string $humanMessage = null, array $details = []): int
    {
        $this->failure = ['code' => $code];

        return parent::renderGatewayFailure($code, $message, $requestId, $humanMessage, $details);
    }
}

final class FirewallAllowPromptFixtureTerminal extends Terminal
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
