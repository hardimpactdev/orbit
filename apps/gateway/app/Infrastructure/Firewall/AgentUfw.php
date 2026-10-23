<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;
use LogicException;

/**
 * UFW on a Node through the agent's `firewall.status` and `firewall.ufw`. Each answer is a command result, so the
 * UFW parsers and the firewall errors read it as they read `ufw` itself. With a connection, the call goes over it,
 * as during a Node's admission.
 */
final readonly class AgentUfw
{
    public function __construct(private AgentCommandRunner $agents, private ?AdmissionAgentRunner $admission = null) {}

    /** `ufw status numbered`, and with `$stored` the stored rule tuples after it, one per line. */
    public function status(Node $node, bool $stored = false, ?float $deadlineSeconds = null, ?SshConnection $over = null): CommandResult
    {
        return $this->result(function () use ($node, $stored, $deadlineSeconds, $over): string {
            $answer = $this->call($node, 'firewall.status', ['stored' => $stored], $deadlineSeconds, $over);
            $tuples = is_array($answer['stored'] ?? null) ? $answer['stored'] : [];

            return (string) ($answer['status'] ?? '').($tuples === [] ? '' : implode("\n", $tuples)."\n");
        });
    }

    /** @param  list<string>  $arguments  The arguments after `ufw`. */
    public function change(Node $node, array $arguments, ?SshConnection $over = null): CommandResult
    {
        return $this->result(fn (): string => (string) ($this->call($node, 'firewall.ufw', ['arguments' => $arguments], null, $over)['stdout'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function call(Node $node, string $command, array $input, ?float $deadlineSeconds, ?SshConnection $over): array
    {
        if ($over === null) {
            return $this->agents->run($node, $command, $input, $deadlineSeconds);
        }

        return ($this->admission ?? throw new LogicException('UFW over a given connection needs the admission runner.'))
            ->runOver($node, $over, $command, $input, $deadlineSeconds);
    }

    /** @param  callable(): string  $run */
    private function result(callable $run): CommandResult
    {
        try {
            return new CommandResult(0, $run(), '', 0, false);
        } catch (AgentCommandRefused $refusal) {
            $details = $refusal->details;

            return new CommandResult(
                is_int($details['exit_code'] ?? null) ? $details['exit_code'] : 1,
                is_string($details['stdout'] ?? null) ? $details['stdout'] : '',
                is_string($details['stderr'] ?? null) ? $details['stderr'] : $refusal->getMessage(),
                0,
                false,
            );
        } catch (AgentRunnerFailure $failure) {
            return $failure->result ?? new CommandResult(255, '', $failure->getMessage(), 0, false);
        }
    }
}
