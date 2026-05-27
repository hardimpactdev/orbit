<?php

declare(strict_types=1);

use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ETopologyKind;

it('acquires app production ingress on the prod node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppprodIngress, withGatewayApi: true);

    try {
        $lease = $topology->lease();
        $prod = $lease->prodApp();
        $ingress = $lease->ingress();

        expect($lease->devApp())->toBeNull()
            ->and($lease->agent())->toBeNull()
            ->and($prod)->not->toBeNull()
            ->and($ingress)->not->toBeNull()
            ->and($ingress?->name())->toBe($prod?->name());

        $gateway = $lease->gateway();

        if ($gateway === null) {
            throw new RuntimeException('App production ingress topology did not return a gateway handle.');
        }

        $prodNode = E2EGatewayApi::getNode($gateway, 'app-prod-1');
        $state = readAppProdIngressState($gateway);

        expect($prodNode['role'])->toBe('app')
            ->and($prodNode['environment'])->toBe('production')
            ->and($prodNode['gateway_endpoint'])->toBe('10.6.0.2')
            ->and($prodNode['wireguard_address'])->toBe('10.6.0.5')
            ->and($state['roles'])->toContain('app-production')
            ->and($state['roles'])->toContain('ingress')
            ->and($state['app_production_ingress_node'])->toBe('app-prod-1')
            ->and($state['node_names'])->toBe(['app-prod-1', 'gateway', 'operator-1']);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway_app-prod_ingress');

/**
 * @return array{roles: list<string>, app_production_ingress_node: string|null, node_names: list<string>}
 */
function readAppProdIngressState(E2EInstance $gateway): array
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-prod-1')->firstOrFail();
$assignments = $node->roleAssignments()
    ->where('status', \App\Enums\Nodes\NodeRoleStatus::Active->value)
    ->orderBy('role')
    ->get(['role', 'settings']);

$appProduction = $assignments->firstWhere('role', \App\Enums\Nodes\NodeRoleName::AppProduction->value);
$appProductionSettings = $appProduction === null ? [] : ($appProduction->settings ?? []);
$ingressNodeId = $appProductionSettings['ingress_node_id'] ?? null;
$ingressNodeName = null;

if ($ingressNodeId !== null) {
    $ingressNodeName = \App\Models\Node::query()->whereKey($ingressNodeId)->value('name');
}

echo json_encode([
    'roles' => $assignments->pluck('role')->values()->all(),
    'app_production_ingress_node' => $ingressNodeName,
    'node_names' => \App\Models\Node::query()->orderBy('name')->pluck('name')->values()->all(),
], JSON_THROW_ON_ERROR);
PHP;

    $encodedPhp = base64_encode($php);

    $result = E2ECommand::orbit(
        $gateway,
        'cd /home/orbit/orbit && orbit tinker --execute='.escapeshellarg("eval(base64_decode('{$encodedPhp}'));"),
        'Could not read prepared app production ingress state',
    );

    /** @var array{roles: list<string>, app_production_ingress_node: string|null, node_names: list<string>} $state */
    $state = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

    return $state;
}
