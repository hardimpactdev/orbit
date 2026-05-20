<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Data\Nodes\RoleSettings\AgentRoleSettings;
use App\Data\Nodes\RoleSettings\AppDevelopmentRoleSettings;
use App\Data\Nodes\RoleSettings\AppProductionRoleSettings;
use App\Data\Nodes\RoleSettings\DatabaseRoleSettings;
use App\Data\Nodes\RoleSettings\EmptyRoleSettings;
use App\Data\Nodes\RoleSettings\VpnRoleSettings;
use App\Enums\Nodes\NodeRoleName;
use InvalidArgumentException;

final class NodeRoleRegistry
{
    /**
     * @return list<NodeRoleDefinition>
     */
    public function definitions(): array
    {
        return array_values($this->definitionMap());
    }

    public function definition(string $role): NodeRoleDefinition
    {
        return $this->definitionMap()[$role]
            ?? throw new InvalidArgumentException("Unknown node role [{$role}].");
    }

    /**
     * @return array<string, NodeRoleDefinition>
     */
    private function definitionMap(): array
    {
        return [
            NodeRoleName::Gateway->value => new NodeRoleDefinition(
                name: NodeRoleName::Gateway->value,
                conflictsWith: [
                    NodeRoleName::AppDevelopment->value,
                    NodeRoleName::AppProduction->value,
                    NodeRoleName::Database->value,
                    NodeRoleName::Agent->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: EmptyRoleSettings::class,
                assignableByRoleCommand: false,
                assignableByNodeNew: true,
            ),
            NodeRoleName::Vpn->value => new NodeRoleDefinition(
                name: NodeRoleName::Vpn->value,
                conflictsWith: [
                    NodeRoleName::AppDevelopment->value,
                    NodeRoleName::AppProduction->value,
                    NodeRoleName::Database->value,
                    NodeRoleName::Agent->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: VpnRoleSettings::class,
                assignableByRoleCommand: false,
                assignableByNodeNew: false,
            ),
            NodeRoleName::AppDevelopment->value => new NodeRoleDefinition(
                name: NodeRoleName::AppDevelopment->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::Vpn->value,
                    NodeRoleName::AppProduction->value,
                    NodeRoleName::Agent->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: AppDevelopmentRoleSettings::class,
            ),
            NodeRoleName::AppProduction->value => new NodeRoleDefinition(
                name: NodeRoleName::AppProduction->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::Vpn->value,
                    NodeRoleName::AppDevelopment->value,
                    NodeRoleName::Agent->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: AppProductionRoleSettings::class,
            ),
            NodeRoleName::Database->value => new NodeRoleDefinition(
                name: NodeRoleName::Database->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::Vpn->value,
                    NodeRoleName::Agent->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: DatabaseRoleSettings::class,
            ),
            NodeRoleName::Agent->value => new NodeRoleDefinition(
                name: NodeRoleName::Agent->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::Vpn->value,
                    NodeRoleName::AppDevelopment->value,
                    NodeRoleName::AppProduction->value,
                    NodeRoleName::Database->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: AgentRoleSettings::class,
                assignableByRoleCommand: false,
                assignableByNodeNew: true,
            ),
        ];
    }
}
