<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use LogicException;

/**
 * Answers `firewall.status` and `firewall.ufw` from queued `ufw` results, and records the `ufw` arguments of each
 * call: `['status', 'numbered']` for a status read.
 */
final class FakeUfwAgents implements AgentCommandRunner
{
    /** @var list<list<string>> */
    public array $arguments = [];

    /** @var list<string> */
    public array $hosts = [];

    /** @param  list<CommandResult>  $results */
    public function __construct(private array $results) {}

    public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        $this->hosts[] = (string) $node->wireguard_ip;
        $this->arguments[] = match ($command) {
            'firewall.status' => ['status', 'numbered'],
            'firewall.ufw' => $input['arguments'],
            default => throw new LogicException("No fake UFW answer for agent command [{$command}]."),
        };
        $result = array_shift($this->results) ?? throw new LogicException('No fake UFW result remains.');

        if (! $result->succeeded()) {
            throw new AgentCommandRefused($command, 'firewall.ufw_failed', 'UFW failed.', ['exit_code' => $result->exitCode, 'stdout' => $result->stdout, 'stderr' => $result->stderr]);
        }

        return $command === 'firewall.status' ? ['status' => $result->stdout, 'stored' => []] : ['stdout' => $result->stdout];
    }

    public function install(Node $node): bool
    {
        return false;
    }
}
