<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

/** Refuses a role on an operating system the role does not support, from the agent's `node.os-release`. */
final readonly class NodeRoleOperatingSystemGuard
{
    public function __construct(
        private AgentCommandRunner $agents,
    ) {}

    public function assert(Node $node, RoleName $role): void
    {
        $wireGuardAddress = $node->wireguard_ip;

        if (! is_string($wireGuardAddress) || $wireGuardAddress === '') {
            throw new NodeRoleOperationException(
                'operating-system',
                'node_role.convergence_failed',
                'node_role.wireguard_missing',
                "Node [{$node->name}] role [{$role->value}] requires a WireGuard address.",
            );
        }

        try {
            $release = $this->agents->run($node, 'node.os-release');
        } catch (AgentCommandRefused $refused) {
            throw $this->unsupported($node, $role, UbuntuRelease::unsupportedText(), AgentStep::output($refused));
        }

        $id = $release['id'] ?? null;
        $codename = $release['codename'] ?? null;
        $supported = array_map(static fn (UbuntuRelease $release): string => $release->value, UbuntuRelease::forRole($role));

        if ($id !== 'ubuntu' || ! in_array($codename, $supported, true)) {
            $text = UbuntuRelease::unsupportedText(is_string($id) ? $id : null, is_string($codename) ? $codename : null);

            throw $this->unsupported($node, $role, $text, new CommandResult(1, '', '', 0, false));
        }
    }

    private function unsupported(Node $node, RoleName $role, string $text, CommandResult $result): NodeRoleOperationException
    {
        return new NodeRoleOperationException(
            'operating-system',
            'node_role.convergence_failed',
            'node_role.operating_system_unsupported',
            "Node [{$node->name}] role [{$role->value}]. {$text}",
            result: $result,
        );
    }
}
