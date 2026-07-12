<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolCatalog;
use RuntimeException;

class AgentRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    public function __construct(
        private readonly DevelopmentDnsMappingEnactor $developmentDnsMappingEnactor = new DevelopmentDnsMappingEnactor,
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

        $tld = $node->tld;

        if (! is_string($tld) || ! $this->isValidTld(trim($tld))) {
            throw new RuntimeException('The agent role requires a valid node TLD.');
        }

        $result = $this->developmentDnsMappingEnactor->convergeDevelopmentRole($node, $tld);

        if (($result['status'] ?? null) === 'not_applicable') {
            throw new RuntimeException(
                'The agent role requires a WireGuard address so the agent DNS mapping can be materialized.',
            );
        }

        $this->convergeAgentUser($node);
        $this->convergeTools($node, ['caddy']);
        $this->convergeTool($node, 'git', 'installed');
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $tld = $node->tld;

        if (is_string($tld) && $this->isValidTld(trim($tld))) {
            $result = $this->developmentDnsMappingEnactor->removeDevelopmentRole($node, $tld);

            if (($result['status'] ?? null) === 'failed') {
                $reason = $result['reason'] ?? 'Failed to remove agent DNS mapping.';

                throw new RuntimeException(is_string($reason) ? $reason : 'Failed to remove agent DNS mapping.');
            }
        }

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
            throw new RuntimeException('Could not ensure Orbit agent runtime ACLs.');
        }
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RunsInternalCommands::class);
    }

    private function isValidTld(string $tld): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld);
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
