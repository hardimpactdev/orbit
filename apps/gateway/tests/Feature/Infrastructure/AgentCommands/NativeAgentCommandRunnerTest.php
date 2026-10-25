<?php

declare(strict_types=1);

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentGitHubToken;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentTransport;
use App\Infrastructure\AgentCommands\AgentTransports;
use App\Infrastructure\AgentCommands\NativeAgentCommandRunner;
use App\Infrastructure\AgentCommands\SshAgentTransport;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Tests\Support\Fleet\FleetTestSsh;
use Tests\Support\ProcessInput;

/** A Node that answers each call from a queue of result lines, and records what it was sent. */
class ScriptedAgentTransport implements AgentTransport
{
    /** @var list<array{arguments: list<string>, envelope: array<string, mixed>, credential: bool, body: string}> */
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

    public function run(array $arguments, ProtectedInput $envelope, float $timeout, ?Closure $output = null): CommandResult
    {
        [$header, $body] = explode("\n", ProcessInput::contents($envelope), 2);
        $decoded = json_decode($header, true, flags: JSON_THROW_ON_ERROR);
        $this->calls[] = ['arguments' => $arguments, 'envelope' => $decoded, 'credential' => $envelope->carriesGitCredential(), 'body' => $body];
        $result = array_shift($this->results) ?? throw new LogicException('No scripted result left.');

        $result = $result instanceof Closure ? $result($decoded) : $result;
        if ($output !== null) {
            $output(new ProcessOutput(ProcessOutputStream::Stdout, $result->stdout));
        }

        return $result;
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

        public function guest(AgentGuest $guest): AgentTransport
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

it('keeps the workspace executor around the read, mismatch installation, and resend', function (): void {
    $node = agentRunnerNode();
    $base = new ScriptedAgentTransport([]);
    $journaled = new ScriptedAgentTransport([
        agentRefusal('agent.version_mismatch'),
        agentResult(['ok' => true, 'data' => ['replaced' => true]]),
        agentResult(['ok' => true, 'data' => ['haves' => [], 'baseline' => null, 'head' => null, 'branch' => 'main']]),
    ]);
    $commands = [];
    $execute = static function (RemoteCommand $command) use ($journaled, &$commands): CommandResult {
        $commands[] = $command;

        return $journaled->run($command->arguments, $command->protectedInput, $command->timeout);
    };

    $result = agentRunner($base)->runInWorkspace($node, $execute, 'task-vms.read-git-refs', ['checkout' => '/home/orbit/worktree'], 42.0);

    expect($result)->toBe(['haves' => [], 'baseline' => null, 'head' => null, 'branch' => 'main'])
        ->and($base->calls)->toBeEmpty()
        ->and($base->staged)->toHaveCount(1)
        ->and(array_map(static fn (RemoteCommand $command): string => $command->arguments[array_key_last($command->arguments)], $commands))
        ->toBe(['task-vms.read-git-refs', 'agent.install', 'task-vms.read-git-refs'])
        ->and($commands[0]->arguments)->toBe(['/usr/local/bin/orbit-agent', 'command', 'task-vms.read-git-refs'])
        ->and($commands[1]->arguments)->toBe(['sudo', '-n', '/var/tmp/orbit-agent-candidate-1', 'command', 'agent.install'])
        ->and($commands[0]->maxOutputBytes)->toBe(AgentTransport::ResultBytes)
        ->and($commands[0]->timeout)->toBeLessThanOrEqual(57.0)
        ->and($journaled->calls[2]['envelope']['deadline_ms'])->toBeLessThanOrEqual($journaled->calls[0]['envelope']['deadline_ms']);
});

it('does not bypass a workspace executor refusal or dispatch another command', function (): void {
    $base = new ScriptedAgentTransport([]);
    $calls = 0;
    $execute = static function (RemoteCommand $command) use (&$calls): CommandResult {
        $calls++;
        throw new RuntimeException('Workspace lease no longer held.');
    };

    expect(fn () => agentRunner($base)->runInWorkspace(agentRunnerNode(), $execute, 'task-vms.read-git-refs', ['checkout' => '/home/orbit/worktree']))
        ->toThrow(RuntimeException::class, 'Workspace lease no longer held.')
        ->and($calls)->toBe(1)
        ->and($base->calls)->toBeEmpty()
        ->and($base->staged)->toBeEmpty();
});

it('streams a pack after an old agent mismatch without buffering its body in results', function (): void {
    $base = new ScriptedAgentTransport([]);
    $wire = "{\"stream_bytes\":6}\nPACK\0\xff{\"ok\":true,\"data\":{\"bytes\":6}}\n";
    $transport = new ScriptedAgentTransport([
        agentRefusal('agent.version_mismatch'),
        agentResult(['ok' => true, 'data' => ['replaced' => true]]),
        new CommandResult(0, $wire, '', 1, false),
    ]);
    $bounds = [];
    $execute = static function (RemoteCommand $command) use ($transport, &$bounds): CommandResult {
        $bounds[] = $command->maxOutputBytes;

        return $transport->run($command->arguments, $command->protectedInput, $command->timeout, $command->output);
    };
    $file = tmpfile();
    $sink = new AgentBinaryOutput($file, 100);
    $result = agentRunner($base)->runInWorkspace(agentRunnerNode(), $execute, 'task-vms.export-git-pack', ['checkout' => '/home/orbit/repo', 'commit' => str_repeat('a', 40), 'haves' => []], output: $sink);
    rewind($file);

    expect($result)->toBe(['bytes' => 6])
        ->and(stream_get_contents($file))->toBe("PACK\0\xff")
        ->and($bounds)->toBe([0, AgentTransport::ResultBytes, 0])
        ->and($base->calls)->toBeEmpty()
        ->and($base->staged)->toHaveCount(1);
    fclose($file);
});

it('never installs or replays an interrupted or oversized binary transfer', function (string $wire, int $bound, string $kind): void {
    $base = new ScriptedAgentTransport([]);
    $transport = new ScriptedAgentTransport([new CommandResult(0, $wire, '', 1, false)]);
    $execute = static fn (RemoteCommand $command): CommandResult => $transport->run($command->arguments, $command->protectedInput, $command->timeout, $command->output);
    $file = tmpfile();
    $sink = new AgentBinaryOutput($file, $bound);

    expect(fn () => agentRunner($base)->runInWorkspace(agentRunnerNode(), $execute, 'task-vms.export-git-pack', [], output: $sink))
        ->toThrow(fn (AgentRunnerFailure $error): bool => $error->kind === $kind && $error->result?->stdout !== 'PACK')
        ->and($transport->calls)->toHaveCount(1)
        ->and($base->staged)->toBeEmpty();
    fclose($file);
})->with([
    'lost result' => ["{\"stream_bytes\":4}\nPACK", 100, AgentRunnerFailure::MissingResult],
    'partial frame' => ["{\"stream_bytes\":4}\nPA", 100, AgentRunnerFailure::MalformedResult],
    'mismatch after data' => ["{\"stream_bytes\":4}\nPACK".json_encode(['ok' => false, 'error' => ['code' => 'agent.version_mismatch']])."\n", 100, AgentRunnerFailure::MalformedResult],
    'sink limit' => ["{\"stream_bytes\":4}\nPACK", 3, AgentRunnerFailure::OutputLimit],
]);

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

// A task VM never receives a GitHub credential; the guard knows one by the mark on the input.
it('sends only a typed GitHub token on input marked as carrying a credential', function (): void {
    $transport = new ScriptedAgentTransport([
        agentResult(['ok' => true, 'data' => ['published' => true]]),
        agentResult(['ok' => true, 'data' => ['published' => true]]),
    ]);
    $runner = agentRunner($transport);
    $access = GitReadEnvironment::forGitHubToken('secret-token');

    $runner->run(agentRunnerNode(), 'instances.check-publication', ['origin' => 'git@github.com:acme/site.git', 'commit' => str_repeat('a', 40), 'git_read' => $access]);
    $runner->run(Node::query()->firstOrFail(), 'instances.check-publication', ['origin' => 'git@github.com:acme/site.git', 'commit' => str_repeat('a', 40), 'git_read' => GitReadEnvironment::none()]);

    expect($transport->calls[0]['envelope']['input']['git_read'])->toBe(['github_token' => 'secret-token'])
        ->and($transport->calls[0]['credential'])->toBeTrue()
        ->and($transport->calls[1]['envelope']['input']['git_read'])->toBe(['github_token' => null])
        ->and($transport->calls[1]['credential'])->toBeFalse();
});

it('marks cache credentials as protected input and keeps them out of argv and debug output', function (): void {
    $transport = new ScriptedAgentTransport([agentResult(['ok' => true, 'data' => ['hashes' => [], 'failures' => []]])]);
    $token = new AgentGitHubToken('ghs_cache_sentinel');

    agentRunner($transport)->run(agentRunnerNode(), 'package-cache.host', ['operation' => 'fill', 'artifacts' => [], 'max_bytes' => 1000, 'github_token' => $token]);

    expect($transport->calls[0]['credential'])->toBeTrue()
        ->and($transport->calls[0]['envelope']['input']['github_token'])->toBe('ghs_cache_sentinel')
        ->and(implode(' ', $transport->calls[0]['arguments']))->not->toContain('ghs_cache_sentinel')
        ->and(print_r($token, true))->not->toContain('ghs_cache_sentinel');
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
    // A pin that failed before is no longer the pin, and a passing installation clears its record.
    $node->forceFill(['agent_failed_pin' => str_repeat('f', 64)])->save();
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

// Only a refusal from agent.install proves the pin cannot install; a lost connection or a failed copy may pass next time.
it('records no failed pin when the candidate cannot be staged or the installer gives no answer', function (string $failure): void {
    $node = agentRunnerNode();
    $transport = $failure === 'staging'
        ? new class([agentRefusal('agent.version_mismatch')]) extends ScriptedAgentTransport
        {
            public function stage(string $localFile): string
            {
                throw new RuntimeException('disk full');
            }
        }
    : new ScriptedAgentTransport([agentRefusal('agent.version_mismatch'), new CommandResult(255, '', 'Connection closed', 1, false)]);

    expect(fn () => agentRunner($transport)->run($node, 'agent.status'))->toThrow(AgentRunnerFailure::class);
    expect($node->fresh()?->agent_failed_pin)->toBeNull();
})->with(['staging', 'connection']);

it('runs a command over the bootstrap connection of a Node that is being admitted', function (): void {
    $transport = new ScriptedAgentTransport([agentResult(['ok' => true, 'data' => (object) ['state' => 'ok']])]);
    $over = new SshConnection('192.0.2.10', 'root', 2222, '/key', '/known', hostIdentity: 'SHA256:pinned');
    $transports = new class($transport) extends AgentTransports
    {
        public ?SshConnection $connection = null;

        public function __construct(private readonly AgentTransport $transport) {}

        public function for(Node $node): AgentTransport
        {
            throw new LogicException('An admitted Node is reached over its bootstrap connection.');
        }

        public function over(Node $node, SshConnection $connection): AgentTransport
        {
            $this->connection = $connection;

            return $this->transport;
        }
    };
    $runner = new NativeAgentCommandRunner(app(AgentContract::class), app(AgentDelivery::class), $transports);

    expect($runner->runOver(agentRunnerNode(), $over, 'agent.status'))->toBe(['state' => 'ok'])
        ->and($transports->connection)->toBe($over);
});

it('keeps the bootstrap connection, with the command deadline, for every call over it', function (): void {
    $connections = [];
    $ssh = new class($connections) implements SshExecutor
    {
        public function __construct(private array &$connections) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->connections[] = $connection;

            return new CommandResult(0, '', '', 1, false);
        }
    };
    $jump = new SshConnection('198.51.100.1', 'orbit', 22, '/key', '/known');
    $over = new SshConnection('192.0.2.10', 'root', 2222, '/key', '/known', proxyJump: $jump, hostIdentity: 'SHA256:pinned');
    $keys = new FleetTestSsh;

    new SshAgentTransport(agentRunnerNode(), $ssh, $keys, $keys, $over)->run(['true'], ProtectedInput::fromString(''), 42.0);

    expect($connections[0])->toEqual(new SshConnection('192.0.2.10', 'root', 2222, '/key', '/known', commandTimeout: 42.0, proxyJump: $jump, hostIdentity: 'SHA256:pinned'));
});

it('keeps a whole agent result above the default process output limit', function (): void {
    $result = json_encode(['ok' => true, 'data' => ['output' => str_repeat('x', 200_000)]], JSON_THROW_ON_ERROR);
    $ssh = new class($result) implements SshExecutor
    {
        public ?RemoteCommand $command = null;

        public function __construct(private string $result) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->command = $command;

            return new CommandResult(0, $this->result, '', 1, false);
        }
    };
    $keys = new FleetTestSsh;

    new SshAgentTransport(agentRunnerNode(), $ssh, $keys, $keys)->run(['true'], ProtectedInput::fromString(''), 42.0);

    expect($ssh->command?->maxOutputBytes)->toBe(AgentTransport::ResultBytes)
        ->and(AgentTransport::ResultBytes)->toBeGreaterThan(6 * 131_072);
});

it('installs a guest on mismatch and resends once through the same runner', function (): void {
    $host = agentRunnerNode();
    $host->forceFill(['agent_failed_pin' => app(AgentDelivery::class)->pin($host)])->save();
    $guest = AgentGuest::incus($host, 'orbit-tasks', 'tvm-image-1');
    $transport = new ScriptedAgentTransport([agentRefusal('agent.version_mismatch'), agentResult(['ok' => true, 'data' => ['replaced' => true]]), agentResult(['ok' => true, 'data' => ['ready' => true]])]);
    $count = Node::query()->count();

    expect(agentRunner($transport)->runGuest($guest, 'agent.status'))->toBe(['ready' => true]);
    expect(array_column($transport->calls, 'arguments'))->toBe([
        ['/usr/local/bin/orbit-agent', 'command', 'agent.status'],
        ['sudo', '-n', '/var/tmp/orbit-agent-candidate-1', 'command', 'agent.install'],
        ['/usr/local/bin/orbit-agent', 'command', 'agent.status'],
    ]);
    expect($host->fresh()->agent_failed_pin)->toBe(app(AgentDelivery::class)->pin($host));
    expect(Node::query()->count())->toBe($count);
});

it('fails an unenrolled guest installation without changing host pin state or adding a Node', function (): void {
    $host = agentRunnerNode();
    $guest = AgentGuest::incus($host, 'orbit-tasks', 'tvm-image-1');
    $transport = new ScriptedAgentTransport([agentRefusal('agent.health_failed')]);
    $count = Node::query()->count();

    expect(fn () => agentRunner($transport)->installGuest($guest))->toThrow(fn (AgentCommandRefused $error) => expect($error->errorCode)->toBe('agent.install_failed'));
    expect($host->fresh()->agent_failed_pin)->toBeNull();
    expect(Node::query()->count())->toBe($count);
});

it('classifies failed SSH candidate staging with its transport result', function (): void {
    $result = new CommandResult(255, '', 'Connection refused', 1, false);
    $ssh = new class($result) implements SshExecutor
    {
        public function __construct(private CommandResult $result) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return $this->result;
        }
    };
    $keys = new FleetTestSsh;
    $file = tempnam(sys_get_temp_dir(), 'agent-stage-');
    file_put_contents($file, 'candidate bytes');
    try {
        expect(fn () => new SshAgentTransport(agentRunnerNode(), $ssh, $keys, $keys)->stage($file))->toThrow(function (AgentRunnerFailure $error) use ($result): void {
            expect($error->kind)->toBe(AgentRunnerFailure::Transport)
                ->and($error->command)->toBe('agent.install')
                ->and($error->result)->toBe($result);
        });
    } finally {
        unlink($file);
    }
});

it('does not record a failed pin before the system bus is ready', function (): void {
    $transport = new ScriptedAgentTransport([agentRefusal('agent.supervisor_not_ready', ['path' => '/run/dbus/system_bus_socket'])]);
    $node = agentRunnerNode();

    expect(fn () => agentRunner($transport)->install($node))->toThrow(function (AgentCommandRefused $error): void {
        expect($error->errorCode)->toBe('agent.supervisor_not_ready');
    });
    expect($node->fresh()->agent_failed_pin)->toBeNull();
});

it('uses the bootstrap login authority before sudo has been installed', function (string $user): void {
    $ssh = Mockery::mock(SshExecutor::class);
    $prefix = $user === 'root' ? [] : ['sudo', '-n'];
    $expected = [...$prefix, '/var/tmp/orbit-agent-candidate-test', 'command', 'agent.install'];
    $ssh->shouldReceive('execute')->once()->withArgs(function (SshConnection $connection, RemoteCommand $command) use ($user, $expected): bool {
        expect($connection->user)->toBe($user)->and($connection->hostIdentity)->toBe('SHA256:pinned');
        expect($command->arguments)->toBe($expected);

        return true;
    })->andReturn(agentResult(['ok' => true, 'data' => ['replaced' => true]]));
    $keys = new FleetTestSsh;
    $connection = new SshConnection('192.0.2.10', $user, 22, '/key', '/known', hostIdentity: 'SHA256:pinned');
    $transport = new SshAgentTransport(agentRunnerNode(), $ssh, $keys, $keys, $connection);
    $transport->run(['sudo', '-n', '/var/tmp/orbit-agent-candidate-test', 'command', 'agent.install'], ProtectedInput::fromString('{}'), 120);
})->with(['root', 'orbit']);

it('selects a first candidate with a bounded architecture probe over pinned SSH', function (int $exit, string $output, ?string $expected): void {
    $ssh = Mockery::mock(SshExecutor::class);
    $result = new CommandResult($exit, $output, '', 1, false);
    $ssh->shouldReceive('execute')->once()->withArgs(function (SshConnection $connection, RemoteCommand $command): bool {
        expect($connection->hostIdentity)->toBe('SHA256:pinned');
        expect($command->arguments)->toBe(['uname', '-sm'])->and($command->timeout)->toBe(10.0);

        return true;
    })->andReturn($result);
    $keys = new FleetTestSsh;
    $connection = new SshConnection('192.0.2.10', 'root', 22, '/key', '/known', hostIdentity: 'SHA256:pinned');
    $transport = new SshAgentTransport(agentRunnerNode(), $ssh, $keys, $keys, $connection);
    if ($expected === null) {
        expect(fn () => $transport->architecture())->toThrow(fn (AgentRunnerFailure $e) => expect($e->result)->toBe($result));
    } else {
        expect($transport->architecture())->toBe($expected);
    }
})->with([[0, "Linux x86_64\n", 'x86_64'], [0, "Linux aarch64\n", 'aarch64'], [0, "\n", null], [0, 'Linux x86_64; touch injected', null], [1, "Linux x86_64\n", null]]);

it('refuses a staging platform mismatch before transferring a candidate', function (string $platform, string $observed): void {
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->once()->andReturn(new CommandResult(0, $observed, '', 1, false));
    $node = agentRunnerNode();
    $node->platform = $platform;
    $keys = new FleetTestSsh;
    expect(fn () => new SshAgentTransport($node, $ssh, $keys, $keys)->architecture())
        ->toThrow(fn (AgentCommandRefused $error) => expect($error->errorCode)->toBe('agent.platform_unsupported'));
})->with([['macos', "Linux aarch64\n"], ['linux', "Darwin arm64\n"]]);

it('stages a Mac candidate at an absolute path without an existing Orbit directory or sudo', function (): void {
    $ssh = Mockery::mock(SshExecutor::class);
    $path = null;
    $ssh->shouldReceive('execute')->once()->ordered()->withArgs(function (SshConnection $connection, RemoteCommand $command) use (&$path): bool {
        expect($connection->user)->toBe('mini');
        expect(array_slice($command->arguments, 0, 4))->toBe(['/usr/bin/install', '-m', '0700', '/dev/null']);
        $path = $command->arguments[4];
        expect($path)->toStartWith('/tmp/orbit-agent-candidate-');

        return true;
    })->andReturn(new CommandResult(0, '', '', 1, false));
    $ssh->shouldReceive('execute')->once()->ordered()->withArgs(function (SshConnection $connection, RemoteCommand $command) use (&$path): bool {
        expect($command->arguments)->toBe(['/bin/dd', 'of='.$path, 'bs=65536']);
        expect(stream_get_contents($command->protectedInput->stream()))->toBe('candidate bytes');

        return true;
    })->andReturn(new CommandResult(0, '', '', 1, false));
    $keys = new FleetTestSsh;
    $node = new Node(['name' => 'mini', 'platform' => 'macos', 'user' => 'mini', 'wireguard_ip' => '10.44.0.40']);
    $file = tempnam(sys_get_temp_dir(), 'agent-stage-');
    file_put_contents($file, 'candidate bytes');
    try {
        expect(new SshAgentTransport($node, $ssh, $keys, $keys)->stage($file))->toStartWith('/tmp/orbit-agent-candidate-');
    } finally {
        unlink($file);
    }
});

it('records an installer failure without persisting unverified admission identity', function (bool $exists): void {
    $node = $exists ? agentRunnerNode() : new Node(['name' => 'not-admitted', 'platform' => 'linux', 'architecture' => 'x86_64']);
    $originalUser = $node->user;
    $node->user = 'unverified';
    $transport = new ScriptedAgentTransport([agentRefusal('agent.health_check_failed')]);
    expect(fn () => agentRunner($transport)->install($node))->toThrow(AgentCommandRefused::class);
    expect($node->agent_failed_pin)->toBe(app(AgentDelivery::class)->pin($node));
    if ($exists) {
        expect($node->fresh()->user)->toBe($originalUser);
        expect($node->fresh()->agent_failed_pin)->toBe($node->agent_failed_pin);
    } else {
        expect($node->exists)->toBeFalse();
        expect(Node::query()->where('name', 'not-admitted')->exists())->toBeFalse();
    }
})->with([true, false]);

it('replays the complete private binary input after mismatch and keeps every call journaled', function (): void {
    $node = agentRunnerNode();
    $base = new ScriptedAgentTransport([]);
    $journaled = new ScriptedAgentTransport([
        agentRefusal('agent.version_mismatch'),
        agentResult(['ok' => true, 'data' => ['replaced' => true]]),
        agentResult(['ok' => true, 'data' => ['imported' => true]]),
    ]);
    $source = tmpfile();
    $bytes = str_repeat("PACK\0\xff\n", 20000);
    fwrite($source, $bytes);
    $binary = AgentBinaryInput::fromFile(stream_get_meta_data($source)['uri'], strlen($bytes));
    fclose($source);
    $execute = static fn (RemoteCommand $command): CommandResult => $journaled->run($command->arguments, $command->protectedInput, $command->timeout);

    $result = agentRunner($base)->runInWorkspace($node, $execute, 'task-vms.import-git-pack', [
        'checkout' => '/home/orbit/worktree', 'default_branch' => null, 'updates' => [],
    ], binaryInput: $binary);

    expect($result)->toBe(['imported' => true])
        ->and($journaled->calls)->toHaveCount(3)
        ->and($journaled->calls[0]['envelope']['stream_bytes'])->toBe(strlen($bytes))
        ->and($journaled->calls[0]['body'])->toBe($bytes)
        ->and($journaled->calls[2]['body'])->toBe($bytes)
        ->and($journaled->calls[1]['body'])->toBe('')
        ->and($base->calls)->toBe([])
        ->and($base->staged)->toHaveCount(1)
        ->and(print_r($binary, true))->not->toContain('PACK');
});

it('rejects binary input missing from a binary command or supplied to a JSON command before transport', function (): void {
    $transport = new ScriptedAgentTransport([]);
    $runner = agentRunner($transport);
    $node = agentRunnerNode();
    expect(fn () => $runner->run($node, 'task-vms.import-git-pack'))->toThrow(LogicException::class);
    $source = tmpfile();
    fwrite($source, 'body');
    $path = stream_get_meta_data($source)['uri'];
    expect(fn () => AgentBinaryInput::fromFile($path, 3))->toThrow(RuntimeException::class);
    $binary = AgentBinaryInput::fromFile($path, 4);
    expect(fn () => $runner->run($node, 'agent.status', binaryInput: $binary))->toThrow(LogicException::class)
        ->and($transport->calls)->toBe([]);
    fclose($source);
});
