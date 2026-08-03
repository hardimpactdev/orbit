<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolCatalog;
use Orbit\Core\Nodes\NodeTld;
use RuntimeException;

class AgentRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    public function __construct(
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
        private readonly ?RunsInternalCommands $localExecutor = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if ($this->nodeRoleAssignments()->nodeIsGateway($node)) {
            throw new RuntimeException('The agent role cannot be assigned to a gateway node.');
        }

        if (! str_starts_with((string) $node->platform, 'ubuntu')) {
            throw new RuntimeException('The agent role requires an Ubuntu host.');
        }

        if (! NodeTld::isValid($node->tld)) {
            throw new RuntimeException('The agent role requires a valid node TLD.');
        }

        if (! is_string($node->wireguard_address) || trim($node->wireguard_address) === '') {
            throw new RuntimeException('The agent role requires a WireGuard address.');
        }

        $this->convergeAgentUser($node);
        $this->convergeTools($node, ['caddy']);
        $this->convergeTool($node, 'git', 'installed');
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $this->removeTools($node, ['caddy', 'git']);
    }

    private function convergeAgentUser(Node $node): void
    {
        $userResult = $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:agent-user:ensure',
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'agent-user.ensure',
                ],
                'timeout' => 60,
                'throw' => false,
            ],
        );

        if (! $userResult->successful()) {
            throw new RuntimeException('Could not ensure the Orbit agent runtime user.');
        }

        $aclResult = $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:agent-acl:ensure',
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'agent-acl.ensure',
                ],
                'timeout' => 180,
                'throw' => false,
            ],
        );

        if (! $aclResult->successful()) {
            $stage = $this->agentAclFailureStage($aclResult->stderr !== '' ? $aclResult->stderr : $aclResult->stdout);

            throw new RuntimeException(
                $stage === null
                    ? 'Could not ensure Orbit agent runtime ACLs.'
                    : "Could not ensure Orbit agent runtime ACLs ({$stage}).",
            );
        }
    }

    private function agentAclFailureStage(string $output): ?string
    {
        if (preg_match('/stage=([a-z0-9_]+)/', $output, $matches) !== 1) {
            return null;
        }

        $stage = $matches[1];

        // Only forward fatal stages emitted by LocalAgentAclEnsure; optional ACL
        // skips are non-fatal metadata and never surface here.
        return match ($stage) {
            'package_index', 'acl_package_install', 'directory_acl', 'config_acl', 'binary_acl' => 'stage='.$stage,
            default => null,
        };
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RunsInternalCommands::class);
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }
}
