<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;

final readonly class E2ETopologyFacts
{
    /**
     * @var array<string, list<string>>
     */
    private const array LOGICAL_ROLES = [
        'Operator' => ['operator'],
        'OperatorGateway' => ['operator', 'gateway'],
        'OperatorGatewayAppdev' => ['operator', 'gateway', 'dev'],
        'OperatorGatewayAppdevAppprod' => ['operator', 'gateway', 'dev', 'prod'],
        'OperatorGatewayAppdevAppprodIngress' => ['operator', 'gateway', 'dev', 'prod', 'ingress'],
        'OperatorGatewayAgent' => ['operator', 'gateway', 'agent'],
        'OperatorGatewayAppdevAppprodAgent' => ['operator', 'gateway', 'dev', 'prod', 'agent'],
        'OperatorGatewayAppprodIngress' => ['operator', 'gateway', 'prod', 'ingress'],
        'OperatorGatewayAppdevWebsocket' => ['operator', 'gateway', 'dev'],
        'OperatorGatewayAppdevAppprodWebsocket' => ['operator', 'gateway', 'dev', 'prod'],
        'OperatorGatewayAppdevAppprodAgentWebsocket' => ['operator', 'gateway', 'dev', 'prod', 'agent'],
    ];

    /**
     * @var array<string, true>
     */
    private const array WEBSOCKET_KINDS = [
        'OperatorGatewayAppdevWebsocket' => true,
        'OperatorGatewayAppdevAppprodWebsocket' => true,
        'OperatorGatewayAppdevAppprodAgentWebsocket' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const array PROD_HOSTS_INGRESS_KINDS = [
        'OperatorGatewayAppdevAppprod' => true,
        'OperatorGatewayAppdevAppprodAgent' => true,
        'OperatorGatewayAppprodIngress' => true,
        'OperatorGatewayAppdevAppprodWebsocket' => true,
        'OperatorGatewayAppdevAppprodAgentWebsocket' => true,
    ];

    /**
     * @var array<string, string>
     */
    private const array WIRE_GUARD_ADDRESSES = [
        'gateway' => '10.6.0.2',
        'operator' => '10.6.0.3',
        'dev' => '10.6.0.4',
        'prod' => '10.6.0.5',
        'agent' => '10.6.0.6',
        'ingress' => '10.6.0.7',
        'websocket' => '10.6.0.8',
    ];

    /**
     * @var array<string, string>
     */
    private const array CANONICAL_ARTIFACT_ROLES = [
        'operator' => 'operator',
        'gateway' => 'gateway',
        'app-dev' => 'app-dev',
        'appdev' => 'app-dev',
        'dev' => 'app-dev',
        'app-prod' => 'app-prod',
        'appprod' => 'app-prod',
        'prod' => 'app-prod',
        'ingress' => 'ingress',
        'edge' => 'ingress',
        'agent' => 'agent',
        'websocket' => 'websocket',
    ];

    /**
     * @var array<string, string>
     */
    private const array DOCKER_ROLES = [
        'app-dev' => 'dev',
        'app-prod' => 'prod',
    ];

    /**
     * @var array<string, string>
     */
    private const array INCUS_ROLES = [
        'operator' => 'operator',
        'app-dev' => 'dev',
        'app-prod' => 'prod',
        'websocket' => 'dev',
    ];

    /**
     * @var array<string, string>
     */
    private const array ARTIFACT_ROLES = [
        'operator' => 'operator',
        'dev' => 'app-dev',
        'prod' => 'app-prod',
    ];

    /**
     * @var array<string, string>
     */
    private const array GATEWAY_NODE_NAMES = [
        'dev' => 'app-dev-1',
        'prod' => 'app-prod-1',
        'agent' => 'agent-1',
        'ingress' => 'edge-1',
        'websocket' => 'app-dev-1',
    ];

    /**
     * @param  list<string>  $logicalRoles
     */
    private function __construct(
        public E2ETopologyKind $kind,
        public array $logicalRoles,
        public bool $usesOperatorGatewayBase,
        public bool $isWebsocketKind,
        public bool $prodHostsIngressRole,
    ) {}

    public static function for(E2ETopologyKind $kind): self
    {
        return new self(
            kind: $kind,
            logicalRoles: self::LOGICAL_ROLES[$kind->name],
            usesOperatorGatewayBase: $kind !== E2ETopologyKind::Operator,
            isWebsocketKind: array_key_exists($kind->name, self::WEBSOCKET_KINDS),
            prodHostsIngressRole: array_key_exists($kind->name, self::PROD_HOSTS_INGRESS_KINDS),
        );
    }

    /**
     * @param  list<string>|null  $defaultRoles
     * @return list<string>
     */
    public function runtimeRoles(?array $defaultRoles = null): array
    {
        return (
            $this->kind === E2ETopologyKind::OperatorGatewayAppprodIngress
                ? ['operator', 'gateway', 'prod']
                : $defaultRoles ?? $this->logicalRoles
        );
    }

    /**
     * @return list<string>
     */
    public function incusRoles(): array
    {
        return array_values(array_filter(
            $this->logicalRoles,
            fn (string $role): bool => $role !== 'ingress' || ! $this->prodHostsIngressRole,
        ));
    }

    public static function canonicalWireGuardAddressForRole(string $role): string
    {
        return self::WIRE_GUARD_ADDRESSES[$role] ?? throw new RuntimeException("Unknown Docker topology role {$role}.");
    }

    public static function canonicalArtifactRole(string $role): ?string
    {
        return self::CANONICAL_ARTIFACT_ROLES[strtolower(trim($role))] ?? null;
    }

    public static function dockerRoleForArtifactRole(string $role): string
    {
        return self::DOCKER_ROLES[$role] ?? $role;
    }

    public static function incusRoleForArtifactRole(string $role): string
    {
        return self::INCUS_ROLES[$role] ?? $role;
    }

    public static function artifactRoleForRuntimeRole(string $role): string
    {
        return self::ARTIFACT_ROLES[$role] ?? $role;
    }

    public static function gatewayNodeNameForRole(string $role): ?string
    {
        return self::GATEWAY_NODE_NAMES[$role] ?? null;
    }
}
