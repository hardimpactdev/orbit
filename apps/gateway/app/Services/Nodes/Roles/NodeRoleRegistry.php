<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Data\Nodes\RoleSettings\AgentRoleSettings;
use App\Data\Nodes\RoleSettings\AnalyticsRoleSettings;
use App\Data\Nodes\RoleSettings\AppDevelopmentRoleSettings;
use App\Data\Nodes\RoleSettings\AppProductionRoleSettings;
use App\Data\Nodes\RoleSettings\DatabaseRoleSettings;
use App\Data\Nodes\RoleSettings\EmptyRoleSettings;
use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Data\Nodes\RoleSettings\VpnRoleSettings;
use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
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
        return $this->definitionMap()[$role] ?? throw new InvalidArgumentException("Unknown node role [{$role}].");
    }

    public function roleIsEligibleForWorkloadNodeCreation(string $role): bool
    {
        $definition = $this->definitionMap()[$role] ?? null;

        return $role !== NodeRoleName::Gateway->value && $definition?->assignableByNodeNew === true;
    }

    /**
     * @param  list<string>  $roles
     * @return array{0: string, 1: string}|null
     */
    public function firstConflictingRolePair(array $roles): ?array
    {
        $definitions = $this->definitionMap();
        $roles = array_values(array_unique($roles));

        foreach ($roles as $index => $firstRole) {
            $firstDefinition = $definitions[$firstRole] ?? null;

            if (! $firstDefinition instanceof NodeRoleDefinition) {
                continue;
            }

            foreach (array_slice($roles, $index + 1) as $secondRole) {
                $secondDefinition = $definitions[$secondRole] ?? null;

                if (! $secondDefinition instanceof NodeRoleDefinition) {
                    continue;
                }

                if (
                    in_array($secondRole, $firstDefinition->conflictsWith, true)
                    || in_array($firstRole, $secondDefinition->conflictsWith, true)
                ) {
                    return [$firstRole, $secondRole];
                }
            }
        }

        return null;
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
                    NodeRoleName::Ingress->value,
                    NodeRoleName::WebSocket->value,
                    NodeRoleName::S3->value,
                    NodeRoleName::Analytics->value,
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
                    NodeRoleName::Ingress->value,
                    NodeRoleName::WebSocket->value,
                    NodeRoleName::S3->value,
                    NodeRoleName::Analytics->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: VpnRoleSettings::class,
                assignableByRoleCommand: false,
                assignableByNodeNew: false,
            ),
            NodeRoleName::Router->value => new NodeRoleDefinition(
                name: NodeRoleName::Router->value,
                conflictsWith: [
                    NodeRoleName::AppDevelopment->value,
                    NodeRoleName::AppProduction->value,
                    NodeRoleName::Database->value,
                    NodeRoleName::Agent->value,
                    NodeRoleName::Ingress->value,
                    NodeRoleName::WebSocket->value,
                    NodeRoleName::S3->value,
                    NodeRoleName::Analytics->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: EmptyRoleSettings::class,
                assignableByRoleCommand: false,
                assignableByNodeNew: false,
            ),
            NodeRoleName::AppDevelopment->value => new NodeRoleDefinition(
                name: NodeRoleName::AppDevelopment->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::Vpn->value,
                    NodeRoleName::Router->value,
                    NodeRoleName::AppProduction->value,
                    NodeRoleName::Agent->value,
                    NodeRoleName::Ingress->value,
                ],
                supportedPlatforms: ['ubuntu', 'macos'],
                settingsClass: AppDevelopmentRoleSettings::class,
            ),
            NodeRoleName::AppProduction->value => new NodeRoleDefinition(
                name: NodeRoleName::AppProduction->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::Vpn->value,
                    NodeRoleName::Router->value,
                    NodeRoleName::AppDevelopment->value,
                    NodeRoleName::Database->value,
                    NodeRoleName::Agent->value,
                    NodeRoleName::WebSocket->value,
                    NodeRoleName::S3->value,
                    NodeRoleName::Analytics->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: AppProductionRoleSettings::class,
            ),
            NodeRoleName::Database->value => new NodeRoleDefinition(
                name: NodeRoleName::Database->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::Vpn->value,
                    NodeRoleName::Router->value,
                    NodeRoleName::AppProduction->value,
                    NodeRoleName::Agent->value,
                    NodeRoleName::Ingress->value,
                ],
                supportedPlatforms: ['ubuntu', 'macos'],
                settingsClass: DatabaseRoleSettings::class,
            ),
            NodeRoleName::Agent->value => new NodeRoleDefinition(
                name: NodeRoleName::Agent->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::Vpn->value,
                    NodeRoleName::Router->value,
                    NodeRoleName::AppDevelopment->value,
                    NodeRoleName::AppProduction->value,
                    NodeRoleName::Database->value,
                    NodeRoleName::Ingress->value,
                    NodeRoleName::WebSocket->value,
                    NodeRoleName::S3->value,
                    NodeRoleName::Metrics->value,
                    NodeRoleName::Analytics->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: AgentRoleSettings::class,
                assignableByRoleCommand: false,
                assignableByNodeNew: true,
            ),
            NodeRoleName::Ingress->value => new NodeRoleDefinition(
                name: NodeRoleName::Ingress->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::Vpn->value,
                    NodeRoleName::Router->value,
                    NodeRoleName::AppDevelopment->value,
                    NodeRoleName::Database->value,
                    NodeRoleName::Agent->value,
                    NodeRoleName::WebSocket->value,
                    NodeRoleName::S3->value,
                    NodeRoleName::Analytics->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: EmptyRoleSettings::class,
            ),
            NodeRoleName::WebSocket->value => new NodeRoleDefinition(
                name: NodeRoleName::WebSocket->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::Vpn->value,
                    NodeRoleName::Router->value,
                    NodeRoleName::AppProduction->value,
                    NodeRoleName::Agent->value,
                    NodeRoleName::Ingress->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: WebSocketRoleSettings::class,
            ),
            NodeRoleName::S3->value => new NodeRoleDefinition(
                name: NodeRoleName::S3->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::Vpn->value,
                    NodeRoleName::Router->value,
                    NodeRoleName::AppProduction->value,
                    NodeRoleName::Agent->value,
                    NodeRoleName::Ingress->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: S3RoleSettings::class,
            ),
            NodeRoleName::Metrics->value => new NodeRoleDefinition(
                name: NodeRoleName::Metrics->value,
                conflictsWith: [
                    NodeRoleName::Agent->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: EmptyRoleSettings::class,
            ),
            NodeRoleName::Analytics->value => new NodeRoleDefinition(
                name: NodeRoleName::Analytics->value,
                conflictsWith: [
                    NodeRoleName::Gateway->value,
                    NodeRoleName::Vpn->value,
                    NodeRoleName::Router->value,
                    NodeRoleName::AppProduction->value,
                    NodeRoleName::Agent->value,
                    NodeRoleName::Ingress->value,
                ],
                supportedPlatforms: ['ubuntu'],
                settingsClass: AnalyticsRoleSettings::class,
            ),
        ];
    }
}
