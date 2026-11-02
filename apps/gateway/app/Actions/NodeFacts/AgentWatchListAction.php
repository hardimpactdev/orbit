<?php

declare(strict_types=1);

namespace App\Actions\NodeFacts;

use App\Actions\Tasks\ListAgentWorkspacesAction;
use App\Data\Tasks\AgentWorkspaceData;
use App\Infrastructure\Nodes\NodeRoleServiceCatalog;
use App\Models\Node;

/**
 * What one Node's agent watches, at start and every 60 seconds. It opens only what this list names: the services
 * of the Node's roles, in addition to the `orbit-process-*` units it always watches, and the task checkouts.
 */
final readonly class AgentWatchListAction
{
    public function __construct(private NodeRoleServiceCatalog $services, private ListAgentWorkspacesAction $workspaces) {}

    /** @return array{services: list<string>, workspaces: list<array<string, mixed>>} */
    public function execute(Node $node): array
    {
        $services = [];
        foreach ($node->roles()->get() as $role) {
            foreach ($this->services->forRole($role->role) as $service) {
                $services[$service.'.service'] = true;
            }
        }
        $services = array_keys($services);
        sort($services);

        return [
            'services' => $services,
            'workspaces' => array_map(static fn (AgentWorkspaceData $workspace): array => $workspace->toArray(), $this->workspaces->execute($node)),
        ];
    }
}
