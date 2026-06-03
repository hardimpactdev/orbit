<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EPreparedTopology
{
    public static function supportedKindsForHelp(): string
    {
        return 'operator, operator_gateway, operator_gateway_app-dev, operator_gateway_app-dev_app-prod, operator_gateway_app-dev_app-prod_ingress, operator_gateway_agent, operator_gateway_app-dev_app-prod_agent, operator_gateway_app-prod_ingress, operator_gateway_app-dev_websocket, operator_gateway_app-dev_app-prod_websocket, and operator_gateway_app-dev_app-prod_agent_websocket';
    }

    /**
     * @return list<E2ETopologyKind>
     */
    public static function artifactSourceKindsFor(E2ETopologyKind $kind): array
    {
        return [self::sourceKindFor($kind)];
    }

    /**
     * @return list<E2ETopologyKind>
     */
    public static function dockerArtifactSourceKindsFor(E2ETopologyKind $kind): array
    {
        if ($kind === E2ETopologyKind::Operator || $kind === E2ETopologyKind::OperatorGateway) {
            return [E2ETopologyKind::OperatorGateway];
        }

        if ($kind === E2ETopologyKind::OperatorGatewayAppdevAppprodIngress) {
            return [
                E2ETopologyKind::OperatorGateway,
                E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
                $kind,
            ];
        }

        if (self::usesOperatorGatewayBase($kind)) {
            return [
                E2ETopologyKind::OperatorGateway,
                self::websocketTopologyKind($kind)
                    ? E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket
                    : E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            ];
        }

        return [$kind];
    }

    public static function sourceKindFor(E2ETopologyKind $kind): E2ETopologyKind
    {
        return self::incusSourceKindFor($kind);
    }

    public static function incusSourceKindFor(E2ETopologyKind $kind): E2ETopologyKind
    {
        if ($kind === E2ETopologyKind::OperatorGatewayAppdevAppprodIngress) {
            return $kind;
        }

        if ($kind === E2ETopologyKind::Operator || self::usesOperatorGatewayBase($kind)) {
            return E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket;
        }

        return $kind;
    }

    public static function supportsKind(E2ETopologyKind $kind): bool
    {
        return $kind === E2ETopologyKind::Operator || self::usesOperatorGatewayBase($kind);
    }

    public static function unsupportedKindMessage(E2ETopologyKind $kind): string
    {
        return "Prepared topology does not support [{$kind->value}]. Supported kinds are ".self::supportedKindsForHelp().'.';
    }

    /**
     * @return list<string>
     */
    public static function artifactRoles(): array
    {
        return ['operator', 'gateway', 'app-dev', 'app-prod', 'ingress', 'agent', 'websocket'];
    }

    /**
     * @return list<string>
     */
    public static function parseArtifactRoles(string $roles): array
    {
        $selected = [];

        foreach (explode(',', $roles) as $role) {
            $role = trim($role);

            if ($role === '') {
                continue;
            }

            $canonical = self::canonicalArtifactRole($role);

            if ($canonical === null) {
                throw new \InvalidArgumentException("Unsupported prepared artifact role [{$role}]. Supported roles are ".implode(', ', self::artifactRoles()).'.');
            }

            if (in_array($canonical, $selected, true)) {
                continue;
            }

            $selected[] = $canonical;
        }

        if ($selected === []) {
            throw new \InvalidArgumentException('At least one prepared artifact role must be provided.');
        }

        return $selected;
    }

    public static function dockerRoleForArtifactRole(string $role): string
    {
        $role = self::requireArtifactRole($role);

        return match ($role) {
            'app-dev' => 'dev',
            'app-prod' => 'prod',
            default => $role,
        };
    }

    public static function incusRoleForArtifactRole(string $role): string
    {
        $role = self::requireArtifactRole($role);

        return match ($role) {
            'operator' => 'operator',
            'app-dev' => 'dev',
            'app-prod' => 'prod',
            'websocket' => 'dev',
            default => $role,
        };
    }

    public static function artifactRoleForDockerRole(string $role): string
    {
        return self::requireArtifactRole($role);
    }

    public static function artifactRoleForIncusRole(string $role): string
    {
        return self::requireArtifactRole($role);
    }

    private static function requireArtifactRole(string $role): string
    {
        $canonical = self::canonicalArtifactRole($role);

        if ($canonical === null) {
            throw new \InvalidArgumentException("Unsupported prepared artifact role [{$role}]. Supported roles are ".implode(', ', self::artifactRoles()).'.');
        }

        return $canonical;
    }

    private static function canonicalArtifactRole(string $role): ?string
    {
        return match (strtolower(trim($role))) {
            'operator' => 'operator',
            'gateway' => 'gateway',
            'app-dev', 'appdev', 'dev' => 'app-dev',
            'app-prod', 'appprod', 'prod' => 'app-prod',
            'ingress', 'edge' => 'ingress',
            'agent' => 'agent',
            'websocket' => 'websocket',
            default => null,
        };
    }

    private static function usesOperatorGatewayBase(E2ETopologyKind $kind): bool
    {
        $currentRoleKinds = [
            E2ETopologyKind::OperatorGateway->value,
            E2ETopologyKind::OperatorGatewayAppdev->value,
            E2ETopologyKind::OperatorGatewayAppdevAppprod->value,
            E2ETopologyKind::OperatorGatewayAppdevAppprodIngress->value,
            E2ETopologyKind::OperatorGatewayAgent->value,
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent->value,
            E2ETopologyKind::OperatorGatewayAppprodIngress->value,
            E2ETopologyKind::OperatorGatewayAppdevWebsocket->value,
            E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket->value,
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket->value,
        ];

        return in_array($kind->value, $currentRoleKinds, true);
    }

    private static function websocketTopologyKind(E2ETopologyKind $kind): bool
    {
        return in_array($kind, [
            E2ETopologyKind::OperatorGatewayAppdevWebsocket,
            E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket,
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
        ], true);
    }

    /**
     * @param  list<string>  $defaultRoles
     * @return list<string>
     */
    public static function runtimeRolesFor(E2ETopologyKind $kind, array $defaultRoles): array
    {
        return match ($kind) {
            E2ETopologyKind::OperatorGatewayAppprodIngress => ['operator', 'gateway', 'prod'],
            default => $defaultRoles,
        };
    }

    public static function prodHostsIngressRole(E2ETopologyKind $kind): bool
    {
        return in_array($kind, [
            E2ETopologyKind::OperatorGatewayAppdevAppprod,
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            E2ETopologyKind::OperatorGatewayAppprodIngress,
            E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket,
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
        ], true);
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    public static function gatewayNodeNamesForRoles(array $roles): array
    {
        $names = ['gateway', 'operator-1'];

        foreach ($roles as $role) {
            $nodeName = match ($role) {
                'dev' => 'app-dev-1',
                'prod' => 'app-prod-1',
                'agent' => 'agent-1',
                'ingress' => 'edge-1',
                'websocket' => 'app-dev-1',
                default => null,
            };

            if ($nodeName === null) {
                continue;
            }

            $names[] = $nodeName;
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, list<string>>
     */
    public static function gatewayAllowedRoleAssignmentsFor(E2ETopologyKind $kind, array $roles): array
    {
        $roles = array_values(array_unique($roles));
        $assignments = [];

        if (in_array('dev', $roles, true)) {
            $assignments['app-dev-1'] = ['app-dev', 'database'];

            if (self::websocketTopologyKind($kind)) {
                $assignments['app-dev-1'][] = 'websocket';
            }
        }

        if (in_array('prod', $roles, true)) {
            $assignments['app-prod-1'] = ['app-prod'];

            if (self::prodHostsIngressRole($kind) && ! in_array('ingress', $roles, true)) {
                $assignments['app-prod-1'][] = 'ingress';
            }
        }

        if (in_array('ingress', $roles, true)) {
            $assignments['edge-1'] = ['ingress'];
        }

        if (in_array('agent', $roles, true)) {
            $assignments['agent-1'] = ['agent'];
        }

        return $assignments;
    }

    /**
     * @param  list<string>  $allowedNodeNames
     * @param  array<string, list<string>>  $allowedRolesByNode
     */
    public static function gatewayRegistryPrunePhp(array $allowedNodeNames, array $allowedRolesByNode = []): string
    {
        $allowedNodeNames = array_values(array_unique($allowedNodeNames));
        $allowedNodeNamesValue = '['.implode(', ', array_map(
            static fn (string $name): string => var_export($name, true),
            $allowedNodeNames,
        )).']';
        $allowedRolesByNodeValue = '['.implode(', ', array_map(
            static fn (string $nodeName, array $roles): string => var_export($nodeName, true).' => ['.implode(', ', array_map(
                static fn (string $role): string => var_export($role, true),
                array_values(array_unique($roles)),
            )).']',
            array_keys($allowedRolesByNode),
            array_values($allowedRolesByNode),
        )).']';

        return <<<PHP
\$allowedNodeNames = {$allowedNodeNamesValue};
\$allowedRolesByNode = {$allowedRolesByNodeValue};

if (in_array('operator-1', \$allowedNodeNames, true)) {
    \$operatorNode = \\App\\Models\\Node::query()
        ->where('name', 'operator-1')
        ->first();
    \$assignedNodeIds = \\App\\Models\\NodeRoleAssignment::query()
        ->where('status', 'active')
        ->distinct()
        ->pluck('node_id');
    \$previousOperatorNode = \\App\\Models\\Node::query()
        ->where('name', '!=', 'operator-1')
        ->when(\$assignedNodeIds->isNotEmpty(), fn (\$query) => \$query->whereNotIn('id', \$assignedNodeIds))
        ->orderBy('id')
        ->first();

    if (\$operatorNode === null && \$previousOperatorNode !== null) {
        \$previousOperatorNode->forceFill(['name' => 'operator-1'])->save();
    }
}

foreach (\$allowedRolesByNode as \$nodeName => \$allowedRoles) {
    \$node = \\App\\Models\\Node::query()
        ->where('name', \$nodeName)
        ->first();

    if (\$node === null) {
        continue;
    }

    \\App\\Models\\NodeRoleAssignment::query()
        ->where('node_id', \$node->id)
        ->where('status', 'active')
        ->whereNotIn('role', \$allowedRoles)
        ->delete();
}

\$staleNodeIds = \\App\\Models\\Node::query()
    ->whereNotIn('name', \$allowedNodeNames)
    ->pluck('id');

if (\$staleNodeIds->isNotEmpty()) {
    \\App\\Models\\FirewallRule::query()
        ->whereIn('node_id', \$staleNodeIds)
        ->delete();
    \\App\\Models\\ProxyRoute::query()
        ->whereIn('node_id', \$staleNodeIds)
        ->delete();
    \\App\\Models\\App::query()
        ->whereIn('node_id', \$staleNodeIds)
        ->delete();
    \\App\\Models\\Node::query()
        ->whereIn('id', \$staleNodeIds)
        ->delete();
}
PHP;
    }
}
