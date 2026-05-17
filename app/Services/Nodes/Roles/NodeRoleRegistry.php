<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Data\Nodes\RoleSettings\AppDevelopmentRoleSettings;
use App\Data\Nodes\RoleSettings\AppProductionRoleSettings;
use App\Data\Nodes\RoleSettings\DatabaseRoleSettings;
use App\Data\Nodes\RoleSettings\EmptyRoleSettings;
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
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: EmptyRoleSettings::class,
                assignableByCommand: false,
            ),
            NodeRoleName::AppDevelopment->value => new NodeRoleDefinition(
                name: NodeRoleName::AppDevelopment->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::AppProduction->value,
                ],
                supportedPlatforms: ['ubuntu', 'macos'],
                settingsClass: AppDevelopmentRoleSettings::class,
            ),
            NodeRoleName::AppProduction->value => new NodeRoleDefinition(
                name: NodeRoleName::AppProduction->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::AppDevelopment->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: AppProductionRoleSettings::class,
            ),
            NodeRoleName::Database->value => new NodeRoleDefinition(
                name: NodeRoleName::Database->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: DatabaseRoleSettings::class,
            ),
        ];
    }
}
