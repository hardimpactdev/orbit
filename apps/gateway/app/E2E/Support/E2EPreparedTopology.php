<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EPreparedTopology
{
    public static function supportedKindsForHelp(): string
    {
        return 'operator, operator_gateway, operator_gateway_app-dev, operator_gateway_app-dev_app-prod, operator_gateway_agent, operator_gateway_app-dev_app-prod_agent, and operator_gateway_app-prod_ingress';
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

        if (self::usesOperatorGatewayBase($kind)) {
            return [E2ETopologyKind::OperatorGateway, E2ETopologyKind::OperatorGatewayAppdevAppprodAgent];
        }

        return [$kind];
    }

    public static function sourceKindFor(E2ETopologyKind $kind): E2ETopologyKind
    {
        return self::incusSourceKindFor($kind);
    }

    public static function incusSourceKindFor(E2ETopologyKind $kind): E2ETopologyKind
    {
        if ($kind === E2ETopologyKind::Operator || self::usesOperatorGatewayBase($kind)) {
            return E2ETopologyKind::OperatorGatewayAppdevAppprodAgent;
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
        return ['operator', 'gateway', 'app-dev', 'app-prod', 'agent'];
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
            'operator' => 'control',
            'app-dev' => 'dev',
            'app-prod' => 'prod',
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
            'operator', 'control' => 'operator',
            'gateway' => 'gateway',
            'app-dev', 'appdev', 'dev' => 'app-dev',
            'app-prod', 'appprod', 'prod' => 'app-prod',
            'agent' => 'agent',
            default => null,
        };
    }

    private static function usesOperatorGatewayBase(E2ETopologyKind $kind): bool
    {
        $currentRoleKinds = [
            E2ETopologyKind::OperatorGateway->value,
            E2ETopologyKind::OperatorGatewayAppdev->value,
            E2ETopologyKind::OperatorGatewayAppdevAppprod->value,
            E2ETopologyKind::OperatorGatewayAgent->value,
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent->value,
            E2ETopologyKind::OperatorGatewayAppprodIngress->value,
        ];

        return in_array($kind->value, $currentRoleKinds, true);
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
        ], true);
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    public static function gatewayNodeNamesForRoles(array $roles): array
    {
        $names = ['gateway', 'control-1'];

        foreach ($roles as $role) {
            $nodeName = match ($role) {
                'dev' => 'app-dev-1',
                'prod' => 'app-prod-1',
                'agent' => 'agent-1',
                'ingress' => 'edge-1',
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
     * @param  list<string>  $allowedNodeNames
     */
    public static function gatewayRegistryPrunePhp(array $allowedNodeNames): string
    {
        $allowedNodeNames = array_values(array_unique($allowedNodeNames));
        $allowedNodeNamesValue = '['.implode(', ', array_map(
            static fn (string $name): string => var_export($name, true),
            $allowedNodeNames,
        )).']';

        return <<<PHP
\$allowedNodeNames = {$allowedNodeNamesValue};
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
