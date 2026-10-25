<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Domain\GitHub\GitReadEnvironment;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;
use Closure;
use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final readonly class NativeAgentCommandRunner implements AdmissionAgentRunner, AgentCommandRunner, GuestAgentCommandRunner, WorkspaceAgentCommandRunner
{
    public const string VersionMismatch = 'agent.version_mismatch';

    public const string InstallFailed = 'agent.install_failed';

    public const string Install = 'agent.install';

    /** Transport time on top of the command's own deadline: connection setup and result delivery. */
    private const float TransportGraceSeconds = 15.0;

    /** An installation after a mismatch waits at most this long, or the call's remaining deadline. */
    private const float InstallWaitSeconds = 120.0;

    public function __construct(
        private AgentContract $contract,
        private AgentDelivery $delivery,
        private AgentTransports $transports,
    ) {}

    #[\Override]
    public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
    {
        return $this->runWith($this->transports->for($node), $node, $command, $input, $deadlineSeconds, $output, $binaryInput);
    }

    #[\Override]
    public function runInWorkspace(Node $node, Closure $execute, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
    {
        return $this->runWith(new WorkspaceAgentTransport($this->transports->for($node), $execute), $node, $command, $input, $deadlineSeconds, $output, $binaryInput);
    }

    #[\Override]
    public function runGuest(AgentGuest $guest, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        return $this->runWith($this->transports->guest($guest), $guest, $command, $input, $deadlineSeconds);
    }

    #[\Override]
    public function installGuest(AgentGuest $guest): bool
    {
        return $this->installPin($guest, $this->transports->guest($guest), $this->delivery->pin($guest), null);
    }

    #[\Override]
    public function runOver(Node $node, SshConnection $connection, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        return $this->runWith($this->transports->over($node, $connection), $node, $command, $input, $deadlineSeconds);
    }

    #[\Override]
    public function installOver(Node $node, SshConnection $connection): bool
    {
        return $this->installPin($node, $this->transports->over($node, $connection), $this->delivery->pin($node), null);
    }

    #[\Override]
    public function architectureOver(Node $node, SshConnection $connection): string
    {
        return $this->transports->architectureOver($node, $connection);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function runWith(AgentTransport $transport, Node|AgentGuest $node, string $command, array $input, ?float $deadlineSeconds, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
    {
        $spec = $this->contract->command($command);
        if (($spec->inputMaxBytes > 0) !== ($binaryInput !== null) || ($binaryInput !== null && $binaryInput->bytes > $spec->inputMaxBytes)) {
            throw new \LogicException('Binary input must match the command and its size limit.');
        }
        if (($spec->outputMaxBytes > 0) !== ($output !== null) || ($output !== null && $output->bytes() !== 0)) {
            throw new \LogicException('A binary output command requires a fresh private sink.');
        }
        if ($spec->privilege($transport->platform()) === null) {
            throw new AgentCommandRefused($command, 'agent.platform_unsupported', "The command [{$command}] does not run on {$transport->platform()}.", ['platform' => $transport->platform()]);
        }

        $pin = $this->delivery->pin($node);
        if ($node instanceof Node && $node->agent_failed_pin === $pin) {
            throw new AgentCommandRefused($command, self::InstallFailed, "The pinned agent failed to install on Node [{$node->name}]; run node:converge to repair it.", ['pin' => $pin]);
        }

        $deadline = $deadlineSeconds === null ? null : microtime(true) + $deadlineSeconds;
        $outcome = $this->send($transport, $spec, $pin, $input, $deadline, output: $output, binaryInput: $binaryInput);
        if (($outcome['error']['code'] ?? null) === self::VersionMismatch) {
            $this->installPin($node, $transport, $pin, $deadline);
            $outcome = $this->send($transport, $spec, $pin, $input, $deadline, output: $output, binaryInput: $binaryInput);
            if (($outcome['error']['code'] ?? null) === self::VersionMismatch) {
                throw new AgentRunnerFailure($command, AgentRunnerFailure::RepeatedMismatch, "Node [{$node->name}] still runs another agent after a passing installation.", $outcome['error']['details']);
            }
        }

        if ($outcome['ok']) {
            return $outcome['data'];
        }

        $error = $outcome['error'];
        if (! $spec->declares($error['code']) && ! in_array($error['code'], $this->contract->commonErrors(), true)) {
            throw new AgentRunnerFailure($command, AgentRunnerFailure::UndeclaredError, "The command [{$command}] returned an undeclared error.", ['code' => $error['code']]);
        }

        throw new AgentCommandRefused($command, $error['code'], $error['message'], $error['details']);
    }

    #[\Override]
    public function install(Node $node): bool
    {
        return $this->installPin($node, $this->transports->for($node), $this->delivery->pin($node), null);
    }

    /** Installs the pinned binary from a staged candidate, which runs the installer itself. */
    private function installPin(Node|AgentGuest $node, AgentTransport $transport, string $pin, ?float $deadline): bool
    {
        $remaining = $deadline === null ? self::InstallWaitSeconds : max(1.0, min(self::InstallWaitSeconds, $deadline - microtime(true)));
        $spec = $this->contract->command(self::Install);

        try {
            $candidate = $transport->stage($this->delivery->localFile($node));
            $outcome = $this->send($transport, $spec, $pin, ['candidate' => $candidate, 'sha256' => $pin], microtime(true) + $remaining, $candidate);
        } catch (AgentRunnerFailure $failure) {
            // No answer proves the pin cannot install, so the next command tries again.
            throw $failure;
        } catch (Throwable $exception) {
            throw new AgentRunnerFailure(self::Install, AgentRunnerFailure::Transport, "The pinned agent could not be staged on Node [{$node->name}].", ['reason' => $exception->getMessage()]);
        }

        if (! $outcome['ok']) {
            // An unavailable precondition changed nothing and does not mark this pin as failed.
            if (in_array($outcome['error']['code'], ['agent.update_busy', 'agent.supervisor_not_ready'], true)) {
                throw new AgentCommandRefused(self::Install, $outcome['error']['code'], $outcome['error']['message'], $outcome['error']['details']);
            }
            if ($node instanceof Node) {
                $this->recordFailedPin($node, $pin);
            }

            throw new AgentCommandRefused(self::Install, self::InstallFailed, "The pinned agent failed to install on Node [{$node->name}].", ['pin' => $pin, 'cause' => $outcome['error']['code'], ...$outcome['error']['details']]);
        }

        if ($node instanceof Node && $node->agent_failed_pin !== null) {
            $this->recordFailedPin($node, null);
        }

        return ($outcome['data']['replaced'] ?? false) === true;
    }

    private function recordFailedPin(Node $node, ?string $pin): void
    {
        $node->forceFill(['agent_failed_pin' => $pin]);
        if ($node->exists) {
            Node::query()->whereKey($node->getKey())->update(['agent_failed_pin' => $pin]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: array{code: string, message: string, details: array<string, mixed>}}
     */
    private function send(AgentTransport $transport, AgentCommandSpec $spec, string $pin, array $input, ?float $deadline, ?string $executable = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
    {
        $budget = (float) $spec->timeoutSeconds;
        if ($deadline !== null) {
            $budget = max(0.0, min($budget, $deadline - microtime(true)));
        }

        $arguments = [
            ...($spec->needsSudo($transport->platform()) ? ['sudo', '-n'] : []),
            $executable ?? $transport->binaryPath(),
            'command',
            $spec->name,
        ];
        $credential = false;
        foreach ($input as $key => $value) {
            if ($value instanceof AgentGitHubToken) {
                $credential = true;
                $input[$key] = $value->value;
            }
            if ($value instanceof GitReadEnvironment) {
                $credential = $credential || ! $value->isEmpty();
                $input[$key] = ['github_token' => $value->githubToken()];
            }
        }
        $envelope = AgentInvocation::envelope($pin, $budget, $input, $binaryInput?->bytes);
        $decoder = $output === null ? null : new AgentOutputDecoder($spec->name, $output, $spec->outputMaxBytes);
        $consume = null;
        if ($decoder !== null) {
            $consume = $decoder->consume(...);
        }

        try {
            $result = $transport->run(
                $arguments,
                $binaryInput?->envelope($envelope, $credential) ?? ($credential ? ProtectedInput::gitCredential($envelope) : ProtectedInput::fromString($envelope)),
                $budget + self::TransportGraceSeconds,
                $consume,
            );
            $result = $decoder?->finish($result) ?? $result;
        } catch (ProcessTimedOutException) {
            throw new AgentRunnerFailure($spec->name, AgentRunnerFailure::Timeout, "The command [{$spec->name}] did not answer before its deadline.");
        }

        return $this->decode($spec->name, $result);
    }

    /** @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: array{code: string, message: string, details: array<string, mixed>}} */
    private function decode(string $command, CommandResult $result): array
    {
        $line = array_find(array_reverse(preg_split('/\R/', $result->stdout) ?: []), fn ($candidate) => trim($candidate) !== '');
        if ($line === null) {
            $kind = $result->exitCode === 255 ? AgentRunnerFailure::Transport : AgentRunnerFailure::MissingResult;

            throw new AgentRunnerFailure($command, $kind, "The command [{$command}] returned no result.", ['exit_code' => $result->exitCode], $result);
        }

        try {
            $value = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AgentRunnerFailure($command, AgentRunnerFailure::MalformedResult, "The command [{$command}] returned a malformed result.", ['exit_code' => $result->exitCode], $result);
        }

        $data = is_array($value) ? self::object($value['data'] ?? null) : null;
        if (is_array($value) && ($value['ok'] ?? null) === true && $data !== null) {
            return ['ok' => true, 'data' => $data];
        }
        $error = is_array($value) ? ($value['error'] ?? null) : null;
        if (is_array($value) && ($value['ok'] ?? null) === false && is_array($error) && is_string($error['code'] ?? null) && is_string($error['message'] ?? null)) {
            return ['ok' => false, 'error' => ['code' => $error['code'], 'message' => $error['message'], 'details' => self::object($error['details'] ?? null) ?? []]];
        }

        throw new AgentRunnerFailure($command, AgentRunnerFailure::MalformedResult, "The command [{$command}] returned a malformed result.", ['exit_code' => $result->exitCode], $result);
    }

    /**
     * A JSON object decoded as an array with string keys, or null for anything else.
     *
     * @return array<string, mixed>|null
     */
    private static function object(mixed $value): ?array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }
        $object = [];
        foreach ($value as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
    }
}
