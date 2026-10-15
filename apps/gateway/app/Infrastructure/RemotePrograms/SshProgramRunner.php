<?php

declare(strict_types=1);

namespace App\Infrastructure\RemotePrograms;

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramFailure;
use App\Domain\RemotePrograms\RemoteProgramLanguage;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Closure;
use JsonException;
use LogicException;
use SensitiveParameter;
use stdClass;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Runs a remote program over SSH: it connects to the Node's WireGuard address as the Node's user and sends the
 * assembled program on standard input with every call, so nothing is installed on the Node.
 */
final readonly class SshProgramRunner implements RemoteProgramRunner
{
    /** A result carries Git evidence such as a worktree listing; it outgrows the 64 KiB process default. */
    private const int OutputLimitBytes = 4 * 1024 * 1024;

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private RemoteProgramCatalog $catalog,
    ) {}

    public function run(
        Node $node,
        RemoteProgram $program,
        array $input = [],
        #[SensitiveParameter] ?GitReadEnvironment $gitRead = null,
        ?float $timeoutSeconds = null,
        ?Closure $cancelled = null,
    ): ProgramResult {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new RemoteProgramFailed($program->name, RemoteProgramFailure::Unreachable);
        }

        $timeout = min($timeoutSeconds ?? (float) $program->timeoutSeconds, (float) $program->timeoutSeconds);
        $command = $program->language === RemoteProgramLanguage::Python
            ? $this->python($program, $input, $gitRead, $timeout, $cancelled)
            : $this->bash($program, $input, $gitRead, $timeout, $cancelled);

        try {
            $result = $this->ssh->execute(
                new SshConnection(
                    host: $node->wireguard_ip,
                    user: $node->user,
                    port: 22,
                    identityFile: $this->keys->privateKeyPath(),
                    knownHostsFile: $this->knownHosts->path(),
                    commandTimeout: $timeout,
                ),
                $command,
            );
        } catch (ProcessTimedOutException $exception) {
            throw new RemoteProgramFailed($program->name, RemoteProgramFailure::TimedOut, previous: $exception);
        } catch (ProcessCancelledException $exception) {
            throw new RemoteProgramFailed($program->name, RemoteProgramFailure::Cancelled, previous: $exception);
        }

        return $program->language === RemoteProgramLanguage::Python
            ? self::pythonResult($program, $result)
            : $this->bashResult($result);
    }

    /** @param  array<array-key, mixed>  $input */
    private function python(
        RemoteProgram $program,
        array $input,
        #[SensitiveParameter] ?GitReadEnvironment $gitRead,
        float $timeout,
        ?Closure $cancelled,
    ): RemoteCommand {
        if ($input !== [] && array_is_list($input)) {
            throw new LogicException("Python program [{$program->name}] takes a JSON object.");
        }

        $variables = $gitRead instanceof GitReadEnvironment ? $gitRead->variables : [];
        $input['git_read'] = $variables === [] ? new stdClass : $variables;
        $payload = $program->source."\0".json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new RemoteCommand(
            arguments: self::pythonArguments($program, $this->catalog->bootstrap()),
            input: $variables === [] ? $payload : null,
            protectedInput: $variables === [] ? null : ProtectedInput::gitCredential($payload),
            maxOutputBytes: self::OutputLimitBytes,
            cancelled: $cancelled,
            timeout: $timeout,
        );
    }

    /** @return list<string> How a Python program starts on a Node. */
    public static function pythonArguments(RemoteProgram $program, string $bootstrap): array
    {
        return [...self::userArguments($program), 'python3', '-I', '-c', $bootstrap, $program->name];
    }

    /**
     * The command that runs a Python program without a Git credential, for a caller with its own SSH executor.
     *
     * @param  array<string, mixed>  $input
     */
    public static function pythonCommand(RemoteProgram $program, string $bootstrap, array $input): RemoteCommand
    {
        $input['git_read'] = new stdClass;

        return new RemoteCommand(
            arguments: self::pythonArguments($program, $bootstrap),
            input: $program->source."\0".json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            maxOutputBytes: self::OutputLimitBytes,
            timeout: (float) $program->timeoutSeconds,
        );
    }

    /** @param  array<array-key, mixed>  $input */
    private function bash(
        RemoteProgram $program,
        array $input,
        #[SensitiveParameter] ?GitReadEnvironment $gitRead,
        float $timeout,
        ?Closure $cancelled,
    ): RemoteCommand {
        if (! array_is_list($input) || array_filter($input, is_string(...)) !== $input) {
            throw new LogicException("Bash program [{$program->name}] takes a list of string arguments.");
        }

        if ($gitRead instanceof GitReadEnvironment && ! $gitRead->isEmpty()) {
            throw new LogicException("Bash program [{$program->name}] takes no Git read credential.");
        }

        return new RemoteCommand(
            arguments: [...self::userArguments($program), 'bash', '-seu', '--', ...$input],
            input: $program->source,
            maxOutputBytes: self::OutputLimitBytes,
            cancelled: $cancelled,
            timeout: $timeout,
        );
    }

    /** @return list<string> */
    private static function userArguments(RemoteProgram $program): array
    {
        return $program->user === null ? [] : ['sudo', '-n', '-u', $program->user, '-H'];
    }

    /**
     * The result of a Python program run. A caller that sends the program through its own SSH executor, such as the PHP
     * step of an Instance, reads its result here, so the result contract stays in one place.
     *
     * @throws RemoteProgramFailed
     */
    public static function pythonResult(RemoteProgram $program, CommandResult $result): ProgramResult
    {
        if (! $result->succeeded()) {
            throw new RemoteProgramFailed($program->name, RemoteProgramFailure::NoResult, $result->stderr, exitCode: $result->exitCode);
        }

        $line = str_ends_with($result->stdout, "\n") ? substr($result->stdout, 0, -1) : null;

        try {
            $decoded = $line === null || $result->truncated || str_contains($line, "\n")
                ? null
                : json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        $outcome = is_array($decoded) ? ($decoded['outcome'] ?? null) : null;
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;

        if (
            ! is_array($decoded)
            || count($decoded) !== 2
            || ! is_string($outcome)
            || ! is_array($data)
            || ($data !== [] && array_is_list($data))
        ) {
            throw new RemoteProgramFailed($program->name, RemoteProgramFailure::InvalidResult, $result->stderr);
        }

        if (! $program->declares($outcome)) {
            throw new RemoteProgramFailed($program->name, RemoteProgramFailure::UndeclaredOutcome, $result->stderr);
        }

        return new ProgramResult($outcome, $data, $result->stderr);
    }

    private function bashResult(CommandResult $result): ProgramResult
    {
        return $result->succeeded()
            ? new ProgramResult('succeeded', ['output' => $result->stdout], $result->stderr)
            : new ProgramResult('failed', ['exit_code' => $result->exitCode, 'output' => $result->stdout], $result->stderr);
    }
}
