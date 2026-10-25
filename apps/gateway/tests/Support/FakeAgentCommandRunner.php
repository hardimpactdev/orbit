<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Models\Node;
use Closure;
use LogicException;
use Throwable;

/**
 * A runner for PHP tests: they test Gateway intent, and Rust tests prove what a command does on a Node.
 * Every call is checked against the real contract, so a test cannot use a command or a refusal code that
 * the agent does not declare, send an input the agent would refuse, or answer with a shape it never returns.
 */
final class FakeAgentCommandRunner implements AgentCommandRunner
{
    /** @var list<array{node: string, command: string, input: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<?float> */
    public array $deadlines = [];

    /** @var list<string> */
    public array $installs = [];

    /** @var array<string, Closure(array<string, mixed>, Node, ?AgentBinaryOutput, ?AgentBinaryInput): array<string, mixed>> */
    private array $answers = [];

    /** @var array<string, true> commands whose answers may break their output schema */
    private array $malformed = [];

    private ?Throwable $installFailure = null;

    private bool $installReplaces = false;

    /** @var (Closure(Node): void)|null */
    private ?Closure $onInstall = null;

    private AgentContract $contract;

    public function __construct(?AgentContract $contract = null)
    {
        $this->contract = $contract ?? app(AgentContract::class);
    }

    /** @param  array<string, mixed>|Closure(array<string, mixed>, Node, ?AgentBinaryOutput, ?AgentBinaryInput): array<string, mixed>  $output */
    public function answer(string $command, array|Closure $output): self
    {
        $this->contract->command($command);
        if (! $output instanceof Closure && ! isset($this->malformed[$command])) {
            AgentSchemas::assertOutput($command, $output);
        }
        $this->answers[$command] = $output instanceof Closure ? $output : static fn (): array => $output;

        return $this;
    }

    /**
     * Answers with a shape the agent never returns, to test the Gateway's own guard against a malformed result.
     *
     * @param  array<string, mixed>|Closure(array<string, mixed>, Node, ?AgentBinaryOutput, ?AgentBinaryInput): array<string, mixed>  $output
     */
    public function answerMalformed(string $command, array|Closure $output): self
    {
        $this->malformed[$command] = true;

        return $this->answer($command, $output);
    }

    /** @param  array<string, mixed>  $details */
    public function refuse(string $command, string $code, array $details = []): self
    {
        $spec = $this->contract->command($command);
        if (! $spec->declares($code) && ! in_array($code, $this->contract->commonErrors(), true)) {
            throw new LogicException("The agent contract does not declare [{$code}] for [{$command}].");
        }
        $this->answers[$command] = static fn (): array => throw new AgentCommandRefused($command, $code, "Refused with {$code}.", $details);

        return $this;
    }

    public function installReplaces(bool $replaces = true): self
    {
        $this->installReplaces = $replaces;

        return $this;
    }

    /** @param  Closure(Node): void  $callback  Runs inside each install, before its result. */
    public function onInstall(Closure $callback): self
    {
        $this->onInstall = $callback;

        return $this;
    }

    public function failInstall(Throwable $failure): self
    {
        $this->installFailure = $failure;

        return $this;
    }

    #[\Override]
    public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
    {
        self::admit($node, $command, $input, $output, $binaryInput, $this->contract);
        $this->deadlines[] = $deadlineSeconds;
        $this->calls[] = ['node' => $node->name, 'command' => $command, 'input' => $input];
        $answer = $this->answers[$command] ?? throw new LogicException("No fake answer for agent command [{$command}].");
        $result = $answer($input, $node, $output, $binaryInput);
        if (! isset($this->malformed[$command])) {
            AgentSchemas::assertOutput($command, $result);
        }

        return $result;
    }

    /**
     * The checks the real runner makes before it sends a command, and the input schema. Every fake calls it.
     *
     * @param  array<string, mixed>  $input
     */
    public static function admit(Node $node, string $command, array $input, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null, ?AgentContract $contract = null): void
    {
        $spec = ($contract ?? app(AgentContract::class))->command($command);
        if (($spec->inputMaxBytes > 0) !== ($binaryInput !== null) || ($binaryInput !== null && $binaryInput->bytes > $spec->inputMaxBytes)) {
            throw new LogicException("Binary input must match the agent contract and its size limit for [{$command}].");
        }
        if (($spec->outputMaxBytes > 0) !== ($output !== null) || ($output !== null && $output->bytes() !== 0)) {
            throw new LogicException("A binary output command needs a fresh sink for [{$command}].");
        }
        $platform = $node->platform === 'macos' ? 'macos' : 'linux';
        if ($spec->privilege($platform) === null) {
            throw new LogicException("The agent command [{$command}] does not run on {$platform}.");
        }
        AgentSchemas::assertInput($command, $input);
    }

    #[\Override]
    public function install(Node $node): bool
    {
        $this->installs[] = $node->name;
        if ($this->onInstall !== null) {
            ($this->onInstall)($node);
        }
        if ($this->installFailure !== null) {
            throw $this->installFailure;
        }

        return $this->installReplaces;
    }

    /** @return list<string> */
    public function commands(): array
    {
        return array_column($this->calls, 'command');
    }
}
