<?php

declare(strict_types=1);

namespace Tests\Support;

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
 * the agent does not declare.
 */
final class FakeAgentCommandRunner implements AgentCommandRunner
{
    /** @var list<array{node: string, command: string, input: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<string> */
    public array $installs = [];

    /** @var array<string, Closure(array<string, mixed>, Node): array<string, mixed>> */
    private array $answers = [];

    private ?Throwable $installFailure = null;

    private bool $installReplaces = false;

    /** @var (Closure(Node): void)|null */
    private ?Closure $onInstall = null;

    private AgentContract $contract;

    public function __construct(?AgentContract $contract = null)
    {
        $this->contract = $contract ?? app(AgentContract::class);
    }

    /** @param  array<string, mixed>|Closure(array<string, mixed>, Node): array<string, mixed>  $output */
    public function answer(string $command, array|Closure $output): self
    {
        $this->contract->command($command);
        $this->answers[$command] = $output instanceof Closure ? $output : static fn (): array => $output;

        return $this;
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
    public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        $this->contract->command($command);
        $this->calls[] = ['node' => $node->name, 'command' => $command, 'input' => $input];
        $answer = $this->answers[$command] ?? throw new LogicException("No fake answer for agent command [{$command}].");

        return $answer($input, $node);
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
