<?php

declare(strict_types=1);

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPreparedTopology;
use App\E2E\Support\E2ETopologyFacts;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusTopologyTemplate;

it('keeps kind role matrices identical across the four topology sources and the facts model', function (E2ETopologyKind $kind): void {
    $facts = E2ETopologyFacts::for($kind);
    $expected = e2e_topology_facts_expected_matrix()[$kind->value];

    expect($facts->logicalRoles)
        ->toBe($expected['logical_roles'])
        ->and($facts->runtimeRoles())
        ->toBe($expected['runtime_roles'])
        ->and($facts->incusRoles())
        ->toBe($expected['incus_roles'])
        ->and($facts->usesOperatorGatewayBase)
        ->toBe($expected['uses_operator_gateway_base'])
        ->and($facts->isWebsocketKind)
        ->toBe($expected['is_websocket_kind'])
        ->and($facts->prodHostsIngressRole)
        ->toBe($expected['prod_hosts_ingress'])
        ->and(DockerTopologyProvider::rolesForKind($kind))
        ->toBe($facts->logicalRoles)
        ->and(DockerTopologyBuilder::rolesFor($kind))
        ->toBe($facts->runtimeRoles())
        ->and(IncusTopologyTemplate::rolesFor($kind))
        ->toBe($facts->incusRoles())
        ->and(E2EPreparedTopology::runtimeRolesFor($kind, DockerTopologyProvider::rolesForKind($kind)))
        ->toBe($facts->runtimeRoles())
        ->and(e2e_invoke_support_method(
            target: E2EPreparedTopology::class,
            method: 'usesOperatorGatewayBase',
            arguments: [$kind],
        ))
        ->toBe($facts->usesOperatorGatewayBase)
        ->and(e2e_invoke_support_method(
            target: E2EPreparedTopology::class,
            method: 'websocketTopologyKind',
            arguments: [$kind],
        ))
        ->toBe($facts->isWebsocketKind)
        ->and(e2e_invoke_support_method(
            target: DockerTopologyBuilder::class,
            method: 'websocketTopologyKind',
            arguments: [$kind],
        ))
        ->toBe($facts->isWebsocketKind)
        ->and(e2e_invoke_support_method(
            target: DockerTopologyProvider::class,
            method: 'websocketTopologyKind',
            arguments: [$kind],
        ))
        ->toBe($facts->isWebsocketKind)
        ->and(E2EPreparedTopology::prodHostsIngressRole($kind))
        ->toBe($facts->prodHostsIngressRole);
})->with(e2e_topology_kind_cases());

it('resolves every topology kind alias to the same facts as the canonical kind', function (
    string $alias,
    E2ETopologyKind $kind,
): void {
    $resolved = E2ETopologyKind::tryFromInput($alias);

    expect($resolved)->toBe($kind);

    $canonical = E2ETopologyFacts::for($kind);
    $aliased = E2ETopologyFacts::for($resolved);

    expect($aliased->logicalRoles)
        ->toBe($canonical->logicalRoles)
        ->and($aliased->runtimeRoles())
        ->toBe($canonical->runtimeRoles())
        ->and($aliased->incusRoles())
        ->toBe($canonical->incusRoles())
        ->and($aliased->isWebsocketKind)
        ->toBe($canonical->isWebsocketKind)
        ->and($aliased->prodHostsIngressRole)
        ->toBe($canonical->prodHostsIngressRole)
        ->and(DockerTopologyProvider::rolesForKind($resolved))
        ->toBe(DockerTopologyProvider::rolesForKind($kind))
        ->and(DockerTopologyBuilder::rolesFor($resolved))
        ->toBe(DockerTopologyBuilder::rolesFor($kind))
        ->and(IncusTopologyTemplate::rolesFor($resolved))
        ->toBe(IncusTopologyTemplate::rolesFor($kind));
})->with(e2e_topology_kind_alias_dataset());

it('owns the canonical WireGuard address map for every runtime role', function (): void {
    $builder = new DockerTopologyBuilder(E2EConfig::fromEnvironment());
    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
    $expected = [
        'gateway' => '10.6.0.2',
        'operator' => '10.6.0.3',
        'dev' => '10.6.0.4',
        'prod' => '10.6.0.5',
        'agent' => '10.6.0.6',
        'ingress' => '10.6.0.7',
        'websocket' => '10.6.0.8',
    ];

    foreach ($expected as $role => $address) {
        expect(E2ETopologyFacts::canonicalWireGuardAddressForRole($role))
            ->toBe($address)
            ->and(e2e_invoke_support_method(
                target: $builder,
                method: 'canonicalWireGuardAddressForRole',
                arguments: [$role],
            ))
            ->toBe($address)
            ->and(e2e_invoke_support_method(
                target: $provider,
                method: 'canonicalWireGuardAddressForRole',
                arguments: [$role],
            ))
            ->toBe($address);
    }

    expect(fn () => E2ETopologyFacts::canonicalWireGuardAddressForRole('unknown'))
        ->toThrow(RuntimeException::class, 'Unknown Docker topology role unknown.');
});

it('owns physical artifact and runtime role slug maps including websocket-to-dev co-location', function (): void {
    expect(E2ETopologyFacts::canonicalArtifactRole('appdev'))
        ->toBe('app-dev')
        ->and(E2ETopologyFacts::canonicalArtifactRole('app-dev'))
        ->toBe('app-dev')
        ->and(E2ETopologyFacts::canonicalArtifactRole('dev'))
        ->toBe('app-dev')
        ->and(E2ETopologyFacts::canonicalArtifactRole('appprod'))
        ->toBe('app-prod')
        ->and(E2ETopologyFacts::canonicalArtifactRole('app-prod'))
        ->toBe('app-prod')
        ->and(E2ETopologyFacts::canonicalArtifactRole('prod'))
        ->toBe('app-prod')
        ->and(E2ETopologyFacts::canonicalArtifactRole('edge'))
        ->toBe('ingress')
        ->and(E2ETopologyFacts::canonicalArtifactRole('ingress'))
        ->toBe('ingress')
        ->and(E2ETopologyFacts::canonicalArtifactRole('websocket'))
        ->toBe('websocket')
        ->and(E2ETopologyFacts::canonicalArtifactRole('operator'))
        ->toBe('operator')
        ->and(E2ETopologyFacts::canonicalArtifactRole('gateway'))
        ->toBe('gateway')
        ->and(E2ETopologyFacts::canonicalArtifactRole('agent'))
        ->toBe('agent')
        ->and(E2ETopologyFacts::canonicalArtifactRole('missing'))
        ->toBeNull()
        ->and(E2ETopologyFacts::dockerRoleForArtifactRole('app-dev'))
        ->toBe('dev')
        ->and(E2ETopologyFacts::dockerRoleForArtifactRole('app-prod'))
        ->toBe('prod')
        ->and(E2ETopologyFacts::dockerRoleForArtifactRole('ingress'))
        ->toBe('ingress')
        ->and(E2EPreparedTopology::dockerRoleForArtifactRole('app-dev'))
        ->toBe('dev')
        ->and(E2ETopologyFacts::incusRoleForArtifactRole('websocket'))
        ->toBe('dev')
        ->and(E2EPreparedTopology::incusRoleForArtifactRole('websocket'))
        ->toBe('dev')
        ->and(E2ETopologyFacts::artifactRoleForRuntimeRole('dev'))
        ->toBe('app-dev')
        ->and(E2ETopologyFacts::artifactRoleForRuntimeRole('prod'))
        ->toBe('app-prod')
        ->and(E2ETopologyFacts::artifactRoleForRuntimeRole('operator'))
        ->toBe('operator')
        ->and(e2e_invoke_support_method(
            target: IncusTopologyTemplate::class,
            method: 'artifactRole',
            arguments: ['dev'],
        ))
        ->toBe('app-dev')
        ->and(e2e_invoke_support_method(
            target: IncusTopologyTemplate::class,
            method: 'artifactRole',
            arguments: ['websocket'],
        ))
        ->toBe('websocket')
        ->and(e2e_invoke_support_method(
            target: DockerTopologyBuilder::class,
            method: 'imageRoleSlug',
            arguments: ['dev'],
        ))
        ->toBe('app-dev')
        ->and(e2e_invoke_support_method(
            target: DockerTopologyBuilder::class,
            method: 'imageRoleSlug',
            arguments: ['prod'],
        ))
        ->toBe('app-prod')
        ->and(E2ETopologyFacts::gatewayNodeNameForRole('dev'))
        ->toBe('app-dev-1')
        ->and(E2ETopologyFacts::gatewayNodeNameForRole('prod'))
        ->toBe('app-prod-1')
        ->and(E2ETopologyFacts::gatewayNodeNameForRole('agent'))
        ->toBe('agent-1')
        ->and(E2ETopologyFacts::gatewayNodeNameForRole('ingress'))
        ->toBe('edge-1')
        ->and(E2ETopologyFacts::gatewayNodeNameForRole('websocket'))
        ->toBe('app-dev-1')
        ->and(E2EPreparedTopology::gatewayNodeNamesForRoles(['operator', 'gateway', 'dev', 'websocket']))
        ->toBe(['gateway', 'operator-1', 'app-dev-1']);
});

it('preserves dedicated ingress versus prod-hosted ingress and websocket-on-dev co-location', function (): void {
    $folded = E2ETopologyFacts::for(E2ETopologyKind::OperatorGatewayAppprodIngress);
    $dedicated = E2ETopologyFacts::for(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress);
    $websocket = E2ETopologyFacts::for(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);

    expect($folded->logicalRoles)
        ->toBe(['operator', 'gateway', 'prod', 'ingress'])
        ->and($folded->runtimeRoles())
        ->toBe(['operator', 'gateway', 'prod'])
        ->and($folded->incusRoles())
        ->toBe(['operator', 'gateway', 'prod'])
        ->and($folded->prodHostsIngressRole)
        ->toBeTrue()
        ->and(DockerTopologyBuilder::rolesFor(E2ETopologyKind::OperatorGatewayAppprodIngress))
        ->toBe(['operator', 'gateway', 'prod'])
        ->and(DockerTopologyProvider::rolesForKind(E2ETopologyKind::OperatorGatewayAppprodIngress))
        ->toBe(['operator', 'gateway', 'prod', 'ingress'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppprodIngress))
        ->toBe(['operator', 'gateway', 'prod'])
        ->and($dedicated->logicalRoles)
        ->toBe(['operator', 'gateway', 'dev', 'prod', 'ingress'])
        ->and($dedicated->runtimeRoles())
        ->toBe(['operator', 'gateway', 'dev', 'prod', 'ingress'])
        ->and($dedicated->incusRoles())
        ->toBe(['operator', 'gateway', 'dev', 'prod', 'ingress'])
        ->and($dedicated->prodHostsIngressRole)
        ->toBeFalse()
        ->and($websocket->logicalRoles)
        ->toBe(['operator', 'gateway', 'dev', 'prod', 'agent'])
        ->and($websocket->isWebsocketKind)
        ->toBeTrue()
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket))
        ->toBe(['operator', 'gateway', 'dev', 'prod', 'agent'])
        ->and(E2EPreparedTopology::gatewayAllowedRoleAssignmentsFor(
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
            ['operator', 'gateway', 'dev', 'prod', 'agent'],
        ))
        ->toBe([
            'app-dev-1' => ['app-dev', 'database', 'websocket'],
            'app-prod-1' => ['app-prod', 'ingress'],
            'agent-1' => ['agent'],
        ])
        ->and(E2EPreparedTopology::gatewayNodeNamesForRoles(['dev', 'websocket']))
        ->toBe(['gateway', 'operator-1', 'app-dev-1']);

    $incusBuilder = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyBuilder.php'));

    expect($incusBuilder)
        ->toContain('IncusTopologyTemplate::rolesFor($kind)')
        ->toContain('IncusTopologyTemplate::rolesFor($stage)');
});

/**
 * @return array<string, array{
 *     logical_roles: list<string>,
 *     runtime_roles: list<string>,
 *     incus_roles: list<string>,
 *     uses_operator_gateway_base: bool,
 *     is_websocket_kind: bool,
 *     prod_hosts_ingress: bool
 * }>
 */
function e2e_topology_facts_expected_matrix(): array
{
    $rows = [
        E2ETopologyKind::Operator->value => [['operator'], false, false, false],
        E2ETopologyKind::OperatorGateway->value => [['operator', 'gateway'], true, false, false],
        E2ETopologyKind::OperatorGatewayAppdev->value => [['operator', 'gateway', 'dev'], true, false, false],
        E2ETopologyKind::OperatorGatewayAppdevAppprod->value => [
            ['operator', 'gateway', 'dev', 'prod'],
            true,
            false,
            true,
        ],
        E2ETopologyKind::OperatorGatewayAppdevAppprodIngress->value => [
            ['operator', 'gateway', 'dev', 'prod', 'ingress'],
            true,
            false,
            false,
        ],
        E2ETopologyKind::OperatorGatewayAgent->value => [['operator', 'gateway', 'agent'], true, false, false],
        E2ETopologyKind::OperatorGatewayAppdevAppprodAgent->value => [
            ['operator', 'gateway', 'dev', 'prod', 'agent'],
            true,
            false,
            true,
        ],
        E2ETopologyKind::OperatorGatewayAppprodIngress->value => [
            ['operator', 'gateway', 'prod', 'ingress'],
            true,
            false,
            true,
            ['operator', 'gateway', 'prod'],
            ['operator', 'gateway', 'prod'],
        ],
        E2ETopologyKind::OperatorGatewayAppdevWebsocket->value => [['operator', 'gateway', 'dev'], true, true, false],
        E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket->value => [
            ['operator', 'gateway', 'dev', 'prod'],
            true,
            true,
            true,
        ],
        E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket->value => [
            ['operator', 'gateway', 'dev', 'prod', 'agent'],
            true,
            true,
            true,
        ],
    ];

    $matrix = [];

    foreach ($rows as $kind => $row) {
        $logicalRoles = $row[0];

        $matrix[$kind] = [
            'logical_roles' => $logicalRoles,
            'runtime_roles' => $row[4] ?? $logicalRoles,
            'incus_roles' => $row[5] ?? $logicalRoles,
            'uses_operator_gateway_base' => $row[1],
            'is_websocket_kind' => $row[2],
            'prod_hosts_ingress' => $row[3],
        ];
    }

    return $matrix;
}

/**
 * @return list<array{0: E2ETopologyKind}>
 */
function e2e_topology_kind_cases(): array
{
    return array_map(
        static fn (E2ETopologyKind $kind): array => [$kind],
        E2ETopologyKind::cases(),
    );
}

/**
 * @return array<string, array{0: string, 1: E2ETopologyKind}>
 */
function e2e_topology_kind_alias_dataset(): array
{
    $aliases = [];

    foreach (E2ETopologyKind::cases() as $kind) {
        $aliases[$kind->value] = [$kind->value, $kind];

        foreach ($kind->deprecatedValues() as $deprecated) {
            $aliases[$deprecated] = [$deprecated, $kind];
        }
    }

    return $aliases;
}

/**
 * @param  list<mixed>  $arguments
 */
function e2e_invoke_support_method(object|string $target, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($target, $method);

    return $reflection->invoke($reflection->isStatic() ? null : $target, ...$arguments);
}
