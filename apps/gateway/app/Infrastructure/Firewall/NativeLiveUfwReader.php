<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Firewall\LiveFirewallBackendStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use Throwable;

final readonly class NativeLiveUfwReader
{
    public function __construct(
        private AgentCommandRunner $agents,
        private AgentContract $contract,
        private CommandDeadline $deadline = new CommandDeadline,
    ) {}

    /**
     * @return array{backend: LiveFirewallBackendStatus, shapes: list<UfwRuleShape>}
     */
    public function read(Node $node): array
    {
        $host = $node->wireguard_ip;

        if ($node->platform !== 'linux' || ! is_string($host) || $host === '') {
            return ['backend' => LiveFirewallBackendStatus::Unreachable, 'shapes' => []];
        }

        try {
            $result = $this->agents->run($node, 'firewall.inspect', [
                'expected' => [], 'exclusive_families' => [], 'stored' => false,
            ], $this->deadline->cap($this->contract->command('firewall.inspect')->timeoutSeconds));
            if (! is_string($result['backend'] ?? null)) {
                throw new \UnexpectedValueException('Invalid firewall observation.');
            }
            $backend = LiveFirewallBackendStatus::from($result['backend']);
            if (! is_array($result['live'] ?? null) || ! array_is_list($result['live'])) {
                throw new \UnexpectedValueException;
            }

            return ['backend' => $backend, 'shapes' => array_map(UfwRuleShape::fromAgentOutput(...), $result['live'])];
        } catch (Throwable) {
            return ['backend' => LiveFirewallBackendStatus::Unreachable, 'shapes' => []];
        }
    }
}
