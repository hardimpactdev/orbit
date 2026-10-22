<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentTransport;
use App\Infrastructure\AgentCommands\AgentTransports;
use App\Infrastructure\AgentCommands\NativeAgentCommandRunner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Models\Node;

/** A Node that answers each call from a queue of result lines, and records what it was sent. */
class ScriptedAgentTransport implements AgentTransport
{
    /** @var list<array{arguments: list<string>, envelope: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<string> */
    public array $staged = [];

    /** @param  list<CommandResult|Closure(array<string, mixed>): CommandResult>  $results */
    public function __construct(public array $results, private string $platform = 'linux') {}

    public function platform(): string
    {
        return $this->platform;
    }

    public function binaryPath(): string
    {
        return '/usr/local/bin/orbit-agent';
    }

    public function run(array $arguments, ProtectedInput $envelope, float $timeout): CommandResult
    {
        $decoded = json_decode((string) stream_get_contents($envelope->stream()), true, flags: JSON_THROW_ON_ERROR);
        $this->calls[] = ['arguments' => $arguments, 'envelope' => $decoded];
        $result = array_shift($this->results) ?? throw new LogicException('No scripted result left.');

        return $result instanceof Closure ? $result($decoded) : $result;
    }

    public function stage(string $localFile): string
    {
        $this->staged[] = $localFile;

        return '/var/tmp/orbit-agent-candidate-'.count($this->staged);
    }
}

function agentResult(array $line, int $exit = 0): CommandResult
{
    return new CommandResult($exit, json_encode($line, JSON_THROW_ON_ERROR)."\n", '', 1, false);
}

function agentRefusal(string $code, array $details = []): CommandResult
{
    return agentResult(['ok' => false, 'error' => ['code' => $code, 'message' => "refused {$code}", 'details' => (object) $details]]);
}

function agentRunner(ScriptedAgentTransport $transport): NativeAgentCommandRunner
{
    $transports = new class($transport) extends AgentTransports
    {
        public function __construct(private readonly AgentTransport $transport) {}

        public function for(Node $node): AgentTransport
        {
            return $this->transport;
        }
    };

    return new NativeAgentCommandRunner(app(AgentContract::class), app(AgentDelivery::class), $transports);
}

function agentRunnerNode(): Node
{
    return Node::query()->forceCreate([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit',
    ]);
}

it('sends the command name and an envelope with the pin, its deadline, and the typed input, and returns the data', function (): void {
    $transport = new ScriptedAgentTransport([agentResult(['ok' => true, 'data' => ['version' => '0.5.0']])]);
    $node = agentRunnerNode();

    expect(agentRunner($transport)->run($node, 'agent.status', [], 4.0))->toBe(['version' => '0.5.0']);

    $call = $transport->calls[0];
    expect($call['arguments'])->toBe(['/usr/local/bin/orbit-agent', 'command', 'agent.status'])
        ->and($call['envelope']['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($node))
        ->and($call['envelope']['deadline_ms'])->toBeGreaterThan(3000)->toBeLessThanOrEqual(4000)
        ->and($call['envelope']['input'])->toBe([]);
});

it('starts a root command through sudo on Linux', function (): void {
    $transport = new ScriptedAgentTransport([agentResult(['ok' => true, 'data' => ['replaced' => false]])]);

    agentRunner($transport)->run(agentRunnerNode(), 'agent.install', ['candidate' => '/var/tmp/x', 'sha256' => str_repeat('a', 64)]);

    expect($transport->calls[0]['arguments'])->toBe(['sudo', '-n', '/usr/local/bin/orbit-agent', 'command', 'agent.install']);
});

it('raises a declared refusal with its code and details', function (): void {
    $transport = new ScriptedAgentTransport([agentRefusal('agent.update_busy', ['lock' => '/run/lock/orbit-self-update.lock'])]);

    expect(fn () => agentRunner($transport)->run(agentRunnerNode(), 'agent.install', ['candidate' => '/x', 'sha256' => str_repeat('a', 64)]))
        ->toThrow(fn (AgentCommandRefused $refused): bool => $refused->errorCode === 'agent.update_busy' && $refused->details === ['lock' => '/run/lock/orbit-self-update.lock']);
});

it('treats an error code the contract does not declare as a runner failure', function (): void {
    $transport = new ScriptedAgentTransport([agentRefusal('agent.made_up')]);

    expect(fn () => agentRunner($transport)->run(agentRunnerNode(), 'agent.status'))
        ->toThrow(fn (AgentRunnerFailure $failure): bool => $failure->kind === AgentRunnerFailure::UndeclaredError);
});

it('tells a lost connection, a missing result, and a malformed result apart', function (CommandResult $result, string $kind): void {
    $transport = new ScriptedAgentTransport([$result]);

    expect(fn () => agentRunner($transport)->run(agentRunnerNode(), 'agent.status'))
        ->toThrow(fn (AgentRunnerFailure $failure): bool => $failure->kind === $kind);
})->with([
    'transport' => [new CommandResult(255, '', 'Connection closed', 1, false), AgentRunnerFailure::Transport],
    'no result' => [new CommandResult(101, '', 'panicked', 1, false), AgentRunnerFailure::MissingResult],
    'malformed' => [new CommandResult(0, "not json\n", '', 1, false), AgentRunnerFailure::MalformedResult],
    'wrong shape' => [new CommandResult(0, "{\"ok\":\"yes\"}\n", '', 1, false), AgentRunnerFailure::MalformedResult],
]);

it('installs the pin from a staged candidate on a version mismatch and sends the command once more', function (): void {
    $node = agentRunnerNode();
    $pin = app(AgentDelivery::class)->pin($node);
    $transport = new ScriptedAgentTransport([
        agentRefusal('agent.version_mismatch', ['expected' => $pin, 'observed' => str_repeat('0', 64)]),
        static function (array $envelope) use ($pin): CommandResult {
            expect($envelope['agent_sha256'])->toBe($pin)
                ->and($envelope['input'])->toBe(['candidate' => '/var/tmp/orbit-agent-candidate-1', 'sha256' => $pin]);

            return agentResult(['ok' => true, 'data' => ['replaced' => true, 'sha256' => $pin, 'previous_sha256' => null, 'observer' => 'restarted']]);
        },
        agentResult(['ok' => true, 'data' => ['version' => '0.5.0']]),
    ]);

    expect(agentRunner($transport)->run($node, 'agent.status'))->toBe(['version' => '0.5.0'])
        ->and(array_column($transport->calls, 'arguments'))->toBe([
            ['/usr/local/bin/orbit-agent', 'command', 'agent.status'],
            ['sudo', '-n', '/var/tmp/orbit-agent-candidate-1', 'command', 'agent.install'],
            ['/usr/local/bin/orbit-agent', 'command', 'agent.status'],
        ])
        ->and($transport->staged)->toBe([app(AgentDelivery::class)->localFile($node)])
        ->and($node->fresh()?->agent_failed_pin)->toBeNull();
});

it('fails instead of retrying again when the Node still mismatches after a passing installation', function (): void {
    $transport = new ScriptedAgentTransport([
        agentRefusal('agent.version_mismatch'),
        agentResult(['ok' => true, 'data' => ['replaced' => true]]),
        agentRefusal('agent.version_mismatch'),
    ]);

    expect(fn () => agentRunner($transport)->run(agentRunnerNode(), 'agent.status'))
        ->toThrow(fn (AgentRunnerFailure $failure): bool => $failure->kind === AgentRunnerFailure::RepeatedMismatch);
    expect($transport->calls)->toHaveCount(3);
});

it('records a failed installation and refuses every later command without contacting the Node until install passes', function (): void {
    $node = agentRunnerNode();
    $transport = new ScriptedAgentTransport([
        agentRefusal('agent.version_mismatch'),
        agentRefusal('agent.health_check_failed', ['observer' => 'restarted']),
    ]);

    expect(fn () => agentRunner($transport)->run($node, 'agent.status'))
        ->toThrow(fn (AgentCommandRefused $refused): bool => $refused->errorCode === 'agent.install_failed' && $refused->details['cause'] === 'agent.health_check_failed');
    expect($node->fresh()?->agent_failed_pin)->toBe(app(AgentDelivery::class)->pin($node));

    $later = new ScriptedAgentTransport([]);
    expect(fn () => agentRunner($later)->run($node->fresh() ?? $node, 'agent.status'))
        ->toThrow(fn (AgentCommandRefused $refused): bool => $refused->errorCode === 'agent.install_failed');
    expect($later->calls)->toBe([]);

    $repair = new ScriptedAgentTransport([agentResult(['ok' => true, 'data' => ['replaced' => true]])]);
    expect(agentRunner($repair)->install($node->fresh() ?? $node))->toBeTrue()
        ->and($node->fresh()?->agent_failed_pin)->toBeNull();
});

it('does not record a failed pin when another installation holds the update lock', function (): void {
    $node = agentRunnerNode();
    $transport = new ScriptedAgentTransport([agentRefusal('agent.version_mismatch'), agentRefusal('agent.update_busy')]);

    expect(fn () => agentRunner($transport)->run($node, 'agent.status'))
        ->toThrow(fn (AgentCommandRefused $refused): bool => $refused->errorCode === 'agent.update_busy');
    expect($node->fresh()?->agent_failed_pin)->toBeNull();
});

it('records a failed pin when the candidate cannot be staged', function (): void {
    $node = agentRunnerNode();
    $transport = new class([agentRefusal('agent.version_mismatch')]) extends ScriptedAgentTransport
    {
        public function stage(string $localFile): string
        {
            throw new RuntimeException('disk full');
        }
    };

    expect(fn () => agentRunner($transport)->run($node, 'agent.status'))
        ->toThrow(fn (AgentRunnerFailure $failure): bool => $failure->details === ['reason' => 'disk full']);
    expect($node->fresh()?->agent_failed_pin)->not->toBeNull();
});
