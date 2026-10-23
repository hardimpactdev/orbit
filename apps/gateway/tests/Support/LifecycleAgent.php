<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use Closure;
use LogicException;
use UnexpectedValueException;

/**
 * Answers `instances.run-lifecycle-step` and records each step's checkout, command, environment, and timeout. The
 * `$result` closure gives an exit status: 75 is a busy checkout, 124 the step's own timeout, and 255 a lost
 * transport. A local agent instead runs the built agent on this host.
 */
final class LifecycleAgent implements AgentCommandRunner
{
    /** @var list<array{checkout: string, command: string, environment: array<string, string>, timeout: int}> */
    public array $inputs = [];

    /** @param (Closure(array{checkout: string, command: string, environment: array<string, string>, timeout: int}): int)|null $result */
    public function __construct(public ?Closure $result = null, public bool $local = false) {}

    public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        if ($command !== 'instances.run-lifecycle-step') {
            throw new LogicException("Unexpected agent command [{$command}].");
        }

        $recorded = $this->record($input);
        $this->inputs[] = $recorded;

        if ($this->local) {
            $host = new Node(['name' => $node->name, 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => $node->user, 'wireguard_ip' => '127.0.0.1']);

            return LocalAgentBinary::localRunner(sys_get_temp_dir().'/orbit-lifecycle-agent-'.bin2hex(random_bytes(6)))->run($host, $command, $input, $deadlineSeconds);
        }

        $exit = $this->result === null ? 0 : ($this->result)($recorded);

        return match ($exit) {
            75 => throw new AgentCommandRefused($command, 'instances.lifecycle_busy', 'A setup or teardown step is running in the checkout.'),
            255 => throw new AgentRunnerFailure($command, AgentRunnerFailure::Transport, 'The connection closed.'),
            default => ['exit_code' => $exit === 124 ? 143 : $exit, 'timed_out' => $exit === 124, 'output' => [], 'truncated' => false],
        };
    }

    public function install(Node $node): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{checkout: string, command: string, environment: array<string, string>, timeout: int}
     */
    private function record(array $input): array
    {
        $program = $input['program'] ?? null;
        $command = is_array($program) ? ($program['command'] ?? null) : $program;
        if (! is_string($input['checkout'] ?? null) || ! is_string($command) || ! is_int($input['timeout_seconds'] ?? null)) {
            throw new UnexpectedValueException('The lifecycle step input is invalid.');
        }

        $environment = [];
        foreach (is_array($input['environment'] ?? null) ? $input['environment'] : [] as $key => $value) {
            $environment[(string) $key] = is_string($value) ? $value : '';
        }

        return ['checkout' => $input['checkout'], 'command' => $command, 'environment' => $environment, 'timeout' => $input['timeout_seconds']];
    }

    public function runner(?CommandDeadline $deadline = null, string $vpHome = '/opt/orbit/vite-plus'): ProjectLifecycleRunner
    {
        return new ProjectLifecycleRunner(
            new ProjectLifecycleStepStore,
            $this,
            $deadline ?? new CommandDeadline,
            ResolvedVp::manager($vpHome),
        );
    }
}
