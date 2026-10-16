<?php

declare(strict_types=1);

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramFailure;
use App\Domain\RemotePrograms\RemoteProgramLanguage;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\RemotePrograms\SshProgramRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** An SSH executor that records each command and answers it, or runs it on this machine when no answer is given. */
final class RemoteProgramTransport implements SshExecutor
{
    /** @var list<array{SshConnection, RemoteCommand}> */
    public array $calls = [];

    public function __construct(private readonly CommandResult|Throwable|null $answer = null) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->calls[] = [$connection, $command];

        if ($this->answer instanceof Throwable) {
            throw $this->answer;
        }

        return $this->answer ?? new NativeProcessRunner()->run(new ProcessInvocation(
            arguments: $command->arguments,
            input: $command->input,
            protectedInput: $command->protectedInput,
        ));
    }
}

function remote_program_runner(RemoteProgramTransport $transport): SshProgramRunner
{
    return new SshProgramRunner(
        $transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 test';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
        new RemoteProgramCatalog,
    );
}

function remote_program_node(?string $address = '10.44.0.9'): Node
{
    return new Node(['name' => 'beast', 'user' => 'orbit', 'wireguard_ip' => $address]);
}

function remote_program_python(string $body, array $outcomes = ['done', 'refused'], int $timeout = 60): RemoteProgram
{
    $runtime = (string) file_get_contents(resource_path('remote/lib/program.py'));

    return new RemoteProgram('tests.program', RemoteProgramLanguage::Python, $runtime.$body, $outcomes, $timeout);
}

function remote_program_result(string $stdout, int $exitCode = 0, bool $truncated = false): CommandResult
{
    return new CommandResult($exitCode, $stdout, 'log line', 3, $truncated);
}

describe('SshProgramRunner', function (): void {
    it('runs a Python program through the bootstrap and returns its declared outcome', function (): void {
        $transport = new RemoteProgramTransport;
        $program = remote_program_python("run_program(lambda payload: ('done', {'echo': payload['value'], 'git_read': payload['git_read']}))\n");

        $result = remote_program_runner($transport)->run(remote_program_node(), $program, ['value' => 'é/ä']);

        [$connection, $command] = $transport->calls[0];
        expect($result->outcome)->toBe('done')
            ->and($result->data)->toBe(['echo' => 'é/ä', 'git_read' => []])
            ->and($command->arguments)->toBe(['python3', '-I', '-c', new RemoteProgramCatalog()->bootstrap(), 'tests.program'])
            ->and($command->input)->toStartWith($program->source."\0")
            ->and($command->protectedInput)->toBeNull()
            ->and($command->timeout)->toBe(60.0)
            ->and($connection->host)->toBe('10.44.0.9')
            ->and($connection->user)->toBe('orbit')
            ->and($connection->commandTimeout)->toBe(60.0);
    });

    it('runs under a selected account without changing the SSH identity', function (RemoteProgramLanguage $language): void {
        $transport = new RemoteProgramTransport(remote_program_result("{\"outcome\":\"done\",\"data\":{}}\n"));
        $program = new RemoteProgram('tests.program', $language, '', ['done'], 60, user: 'orbit-app-1');

        remote_program_runner($transport)->run(remote_program_node(), $program);

        [$connection, $command] = $transport->calls[0];
        expect(array_slice($command->arguments, 0, 5))->toBe(['sudo', '-n', '-u', 'orbit-app-1', '-H']);
        expect($command->arguments[5])->toBe($language === RemoteProgramLanguage::Python ? 'python3' : 'bash');
        expect($connection->user)->toBe('orbit');
    })->with([RemoteProgramLanguage::Python, RemoteProgramLanguage::Bash]);

    it('rejects unsafe account names before a program can run', function (string $user): void {
        expect(fn () => new RemoteProgramCatalog()->program('tools.read-vp-project', user: $user))
            ->toThrow(InvalidArgumentException::class);
    })->with(['-root', 'orbit;id', "orbit\nroot", 'orbit app', '', str_repeat('a', 33)]);

    it('sends a Git read credential only as protected input that is marked as a credential', function (): void {
        $transport = new RemoteProgramTransport(remote_program_result("{\"outcome\":\"done\",\"data\":{}}\n"));

        remote_program_runner($transport)->run(remote_program_node(), remote_program_python(''), [], GitReadEnvironment::forGitHubToken('secret-token'));

        $command = $transport->calls[0][1];
        $payload = stream_get_contents($command->protectedInput?->stream() ?? throw new LogicException('No protected input.'));
        expect($command->input)->toBeNull()
            ->and($command->protectedInput?->carriesGitCredential())->toBeTrue()
            ->and(implode(' ', $command->arguments))->not->toContain('secret-token')
            ->and($payload)->toContain('"GIT_CONFIG_COUNT":"3"');
    });

    it('keeps a caller timeout below the program timeout', function (): void {
        $transport = new RemoteProgramTransport(remote_program_result("{\"outcome\":\"done\",\"data\":{}}\n"));

        remote_program_runner($transport)->run(remote_program_node(), remote_program_python(''), [], timeoutSeconds: 5.0);
        remote_program_runner($transport)->run(remote_program_node(), remote_program_python(''), [], timeoutSeconds: 500.0);

        expect($transport->calls[0][1]->timeout)->toBe(5.0)
            ->and($transport->calls[1][1]->timeout)->toBe(60.0);
    });

    it('fails a run without a declared outcome', function (CommandResult|Throwable $answer, RemoteProgramFailure $reason): void {
        $runner = remote_program_runner(new RemoteProgramTransport($answer));

        expect(fn () => $runner->run(remote_program_node(), remote_program_python(''), []))
            ->toThrow(fn (RemoteProgramFailed $exception) => expect($exception->reason)->toBe($reason)
                ->and($exception->program)->toBe('tests.program'));
    })->with([
        'a nonzero exit' => [remote_program_result("{\"outcome\":\"done\",\"data\":{}}\n", 1), RemoteProgramFailure::NoResult],
        'no output' => [remote_program_result(''), RemoteProgramFailure::InvalidResult],
        'no final newline' => [remote_program_result('{"outcome":"done","data":{}}'), RemoteProgramFailure::InvalidResult],
        'two lines' => [remote_program_result("{\"outcome\":\"done\",\"data\":{}}\n{\"outcome\":\"done\",\"data\":{}}\n"), RemoteProgramFailure::InvalidResult],
        'not JSON' => [remote_program_result("done\n"), RemoteProgramFailure::InvalidResult],
        'data that is a list' => [remote_program_result("{\"outcome\":\"done\",\"data\":[1]}\n"), RemoteProgramFailure::InvalidResult],
        'an extra field' => [remote_program_result("{\"outcome\":\"done\",\"data\":{},\"more\":1}\n"), RemoteProgramFailure::InvalidResult],
        'truncated output' => [remote_program_result("{\"outcome\":\"done\",\"data\":{}}\n", truncated: true), RemoteProgramFailure::InvalidResult],
        'an undeclared outcome' => [remote_program_result("{\"outcome\":\"surprise\",\"data\":{}}\n"), RemoteProgramFailure::UndeclaredOutcome],
        'a timeout' => [new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL), RemoteProgramFailure::TimedOut],
        'a cancellation' => [new ProcessCancelledException, RemoteProgramFailure::Cancelled],
    ]);

    it('keeps the exit status and log of a run without a result', function (): void {
        $runner = remote_program_runner(new RemoteProgramTransport(new CommandResult(3, '', "tests.program: Orbit remote programs need Python 3.10 or newer\n", 5, false)));

        expect(fn () => $runner->run(remote_program_node(), remote_program_python(''), []))
            ->toThrow(fn (RemoteProgramFailed $exception) => expect($exception->exitCode)->toBe(3)
                ->and($exception->log)->toContain('need Python 3.10 or newer'));
    });

    it('fails before SSH for a Node without a WireGuard address', function (): void {
        $transport = new RemoteProgramTransport;

        expect(fn () => remote_program_runner($transport)->run(remote_program_node(null), remote_program_python(''), []))
            ->toThrow(fn (RemoteProgramFailed $exception) => expect($exception->reason)->toBe(RemoteProgramFailure::Unreachable));
        expect($transport->calls)->toBe([]);
    });

    it('runs a Bash program with positional arguments and maps its exit status', function (): void {
        $transport = new RemoteProgramTransport;
        $program = new RemoteProgram('tests.check', RemoteProgramLanguage::Bash, "printf '%s' \"\$1\"\ntest \"\$2\" = yes\n", ['succeeded', 'failed'], 10);

        $passed = remote_program_runner($transport)->run(remote_program_node(), $program, ['hello world', 'yes']);
        $failed = remote_program_runner($transport)->run(remote_program_node(), $program, ['again', 'no']);

        expect($transport->calls[0][1]->arguments)->toBe(['bash', '-seu', '--', 'hello world', 'yes'])
            ->and($passed->outcome)->toBe('succeeded')
            ->and($passed->data)->toBe(['output' => 'hello world'])
            ->and($failed->outcome)->toBe('failed')
            ->and($failed->data)->toBe(['exit_code' => 1, 'output' => 'again']);
    });

    it('refuses input of the wrong shape before SSH', function (RemoteProgram $program, array $input, ?GitReadEnvironment $gitRead): void {
        $transport = new RemoteProgramTransport;

        expect(fn () => remote_program_runner($transport)->run(remote_program_node(), $program, $input, $gitRead))->toThrow(LogicException::class);
        expect($transport->calls)->toBe([]);
    })->with([
        'a list for Python' => fn (): array => [remote_program_python(''), ['a'], null],
        'an object for Bash' => fn (): array => [new RemoteProgram('tests.check', RemoteProgramLanguage::Bash, "true\n", ['succeeded', 'failed'], 10), ['key' => 'a'], null],
        'a credential for Bash' => fn (): array => [new RemoteProgram('tests.check', RemoteProgramLanguage::Bash, "true\n", ['succeeded', 'failed'], 10), [], GitReadEnvironment::forGitHubToken('secret-token')],
    ]);
});
