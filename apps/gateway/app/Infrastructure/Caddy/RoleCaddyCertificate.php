<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy;

use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use SensitiveParameter;

/**
 * A role's Orbit CA certificate in a directory that Node Caddy reads, through the agent's
 * `caddy.install-certificate` and `caddy.remove-certificate`. The agent swaps the directory and reloads Caddy
 * under the Caddy publication lock. Each answer is a command result, so a role reads it as before.
 */
final readonly class RoleCaddyCertificate
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function publish(
        Node $node,
        string $directory,
        string $name,
        string $service,
        string $certificatePem,
        #[SensitiveParameter] string $privateKeyPem,
    ): CommandResult {
        return $this->result(fn () => $this->agents->run($node, 'caddy.install-certificate', [
            'directory' => $directory,
            'certificate_file' => "{$name}.pem",
            'key_file' => "{$name}.key",
            'certificate' => $certificatePem,
            'key' => $privateKeyPem,
            'service' => $service,
        ]));
    }

    public function remove(Node $node, string $directory): CommandResult
    {
        return $this->result(fn () => $this->agents->run($node, 'caddy.remove-certificate', ['directory' => $directory]));
    }

    /** @param  callable(): array<string, mixed>  $run */
    private function result(callable $run): CommandResult
    {
        try {
            $run();

            return new CommandResult(0, '', '', 0, false);
        } catch (AgentCommandRefused $refusal) {
            return new CommandResult(1, AgentStep::output($refusal)->stdout, $refusal->errorCode.': '.$refusal->getMessage(), 0, false);
        } catch (AgentRunnerFailure $failure) {
            return $failure->result ?? new CommandResult(255, '', $failure->getMessage(), 0, false);
        }
    }
}
