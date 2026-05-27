<?php

declare(strict_types=1);

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EPreparedTopology;
use App\E2E\Support\E2ETopologyArtifactNamespace;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusTopologyTemplate;

it('maps current-role topology requests to the operator-gateway source artifact', function (): void {
    expect(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::Control))->toBe(E2ETopologyKind::Operator)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::ControlGateway))->toBe(E2ETopologyKind::OperatorGateway)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::ControlGatewayDev))->toBe(E2ETopologyKind::OperatorGateway)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::ControlGatewayDevProd))->toBe(E2ETopologyKind::OperatorGateway)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGatewayAgent))->toBe(E2ETopologyKind::OperatorGateway)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent))->toBe(E2ETopologyKind::OperatorGateway)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGatewayAppprodIngress))->toBe(E2ETopologyKind::OperatorGateway);
});

it('keeps Docker artifacts scoped to standalone operator and requested topology images', function (): void {
    expect(E2EPreparedTopology::dockerArtifactSourceKindsFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent))
        ->toBe([E2ETopologyKind::OperatorGatewayAppdevAppprodAgent])
        ->and(E2EPreparedTopology::dockerArtifactSourceKindsFor(E2ETopologyKind::OperatorGatewayAgent))
        ->toBe([E2ETopologyKind::OperatorGatewayAgent])
        ->and(E2EPreparedTopology::dockerSourceKindFor(E2ETopologyKind::OperatorGatewayAppprodIngress))
        ->toBe(E2ETopologyKind::OperatorGatewayAppprodIngress)
        ->and(E2EPreparedTopology::dockerArtifactSourceKindsFor(E2ETopologyKind::Operator))
        ->toBe([E2ETopologyKind::Operator]);
});

it('sources Incus downstream roles from the prepared full snapshot', function (): void {
    expect(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::Operator))
        ->toBe(E2ETopologyKind::Operator)
        ->and(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGateway))
        ->toBe(E2ETopologyKind::OperatorGateway)
        ->and(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGatewayAppdev))
        ->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent)
        ->and(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGatewayAgent))
        ->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent)
        ->and(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGatewayAppprodIngress))
        ->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);
});

it('collapses app production ingress onto the prod role', function (): void {
    expect(E2EPreparedTopology::runtimeRolesFor(
        E2ETopologyKind::OperatorGatewayAppprodIngress,
        ['operator', 'gateway', 'prod', 'ingress'],
    ))->toBe(['operator', 'gateway', 'prod'])
        ->and(E2EPreparedTopology::prodHostsIngressRole(E2ETopologyKind::OperatorGatewayAppprodIngress))->toBeTrue()
        ->and(E2EPreparedTopology::prodHostsIngressRole(E2ETopologyKind::OperatorGatewayAppdevAppprod))->toBeTrue()
        ->and(E2EPreparedTopology::prodHostsIngressRole(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent))->toBeTrue();
});

it('builds a gateway registry prune script that removes stale topology rows', function (): void {
    $script = E2EPreparedTopology::gatewayRegistryPrunePhp(['gateway', 'control-1']);

    expect($script)
        ->toContain("['gateway', 'control-1']")
        ->toContain('whereNotIn')
        ->toContain('FirewallRule::query()')
        ->toContain('ProxyRoute::query()')
        ->toContain('App::query()')
        ->toContain('Node::query()');
});

it('does not retain a split ingress node when app production ingress boots only the prod role', function (): void {
    expect(E2EPreparedTopology::gatewayNodeNamesForRoles(['control', 'gateway', 'prod']))
        ->toBe(['gateway', 'control-1', 'app-prod-1'])
        ->not->toContain('edge-1');
});

it('uses a separate topology artifact namespace by default', function (): void {
    expect(DockerTopologyBuilder::imageNameFor(E2ETopologyKind::OperatorGateway, 'control', 'dns-alias'))
        ->toBe('orbit-e2e-topology:prepared-operator_gateway-operator-dns-alias-current')
        ->and(DockerTopologyBuilder::runtimeImage())
        ->toBe('orbit-e2e-topology-runtime:prepared-current')
        ->and(DockerTopologyProvider::runtimeSiblingImage())
        ->toBe('orbit-runtime:prepared-current')
        ->and(E2ETopologyArtifactNamespace::dockerBuildName('orbit-e2e', E2ETopologyKind::OperatorGateway))
        ->toBe('orbit-e2e-prepared-build-operator_gateway')
        ->and(E2ETopologyArtifactNamespace::runtimeInstancePrefix('orbit-e2e'))
        ->toBe('orbit-e2e-prepared')
        ->and(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGateway, 'control'))
        ->toBe('orbit-template-prepared-control')
        ->and(IncusTopologyTemplate::snapshotName(E2ETopologyKind::OperatorGateway))
        ->toBe('clean-prepared-operator_gateway');
});

it('allows a custom topology artifact namespace for isolated benchmark runs', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Branch A/B',
    ], function (): void {
        expect(DockerTopologyBuilder::imageNameFor(E2ETopologyKind::OperatorGateway, 'control'))
            ->toBe('orbit-e2e-topology:branch-a-b-operator_gateway-operator-dns-alias-current')
            ->and(DockerTopologyBuilder::runtimeImage())
            ->toBe('orbit-e2e-topology-runtime:branch-a-b-current')
            ->and(DockerTopologyProvider::runtimeSiblingImage())
            ->toBe('orbit-runtime:branch-a-b-current')
            ->and(E2ETopologyArtifactNamespace::runtimeInstancePrefix('orbit-e2e'))
            ->toBe('orbit-e2e-branch-a-b')
            ->and(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGateway, 'control'))
            ->toBe('orbit-template-branch-a-b-control')
            ->and(IncusTopologyTemplate::snapshotName(E2ETopologyKind::OperatorGateway))
            ->toBe('clean-branch-a-b-operator_gateway');
    });
});
