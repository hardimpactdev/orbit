<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');

it('restores the metrics router route with Orbit-managed TLS on a prepared app VM', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true)
        ->withCurrentCheckout(roles: ['gateway']);
    $checkout = escapeshellarg($topology->checkout('gateway'));

    try {
        e2eRestartGatewayApi($topology, 'metrics-route-doctor');
        metricsRouteDoctorSeedRouteIntent($topology, $checkout);

        $topology->ssh(
            'dev',
            'sudo rm -f /etc/caddy/sites/metrics.orbit.caddy /etc/orbit/certs/metrics.orbit.crt /etc/orbit/certs/metrics.orbit.key',
            timeoutSeconds: 60,
        );

        $restore = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit doctor --node=app-dev-1 --family=proxy --key=proxy.route_missing --restore --json",
            timeoutSeconds: 300,
        );
        $doctorData = e2eJsonCommandData(e2eJsonCommandPayload($restore->output()));

        expect($restore->successful())
            ->toBeTrue($restore->output().$restore->errorOutput())
            ->and($doctorData['doctor']['healthy'])
            ->toBeTrue(json_encode($doctorData, JSON_PRETTY_PRINT));

        $artifacts = $topology->ssh(
            'dev',
            'test -s /etc/caddy/sites/metrics.orbit.caddy'
            .' && test -s /etc/orbit/certs/metrics.orbit.crt'
            .' && test -s /etc/orbit/certs/metrics.orbit.key'
            .' && sudo grep -F "tls /etc/orbit/certs/metrics.orbit.crt /etc/orbit/certs/metrics.orbit.key" /etc/caddy/sites/metrics.orbit.caddy'
            .' && sudo grep -F "reverse_proxy http://host.docker.internal:3000" /etc/caddy/sites/metrics.orbit.caddy',
            timeoutSeconds: 60,
        );

        expect($artifacts->successful())->toBeTrue($artifacts->output().$artifacts->errorOutput());
    } finally {
        $topology->ssh(
            'dev',
            'sudo rm -f /etc/caddy/sites/metrics.orbit.caddy /etc/orbit/certs/metrics.orbit.crt /etc/orbit/certs/metrics.orbit.key',
            timeoutSeconds: 60,
            allowFailure: true,
        );
        $topology->cleanup();
    }
});

function metricsRouteDoctorSeedRouteIntent(E2ETopologyHarness $topology, string $checkout): void
{
    $script = <<<'PHP'
        $node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
        $config = [
            'owner_name' => 'grafana',
            'protocol' => 'http',
            'target' => [
                'type' => 'upstream',
                'value' => 'http://127.0.0.1:3000',
            ],
            'upstreams' => [
                ['scheme' => 'http', 'host' => '127.0.0.1', 'port' => 3000],
            ],
        ];
        $route = new \App\Models\ProxyRoute([
            'node_id' => $node->id,
            'domain' => 'metrics.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => $config,
        ]);
        $sourceHash = app(\App\Services\Proxy\ProxyRouteRenderer::class)->sourceHash($route);

        \App\Models\ProxyRoute::query()->updateOrCreate(
            ['domain' => 'metrics.orbit'],
            [
                'node_id' => $node->id,
                'app_id' => null,
                'workspace_id' => null,
                'owner_type' => 'router',
                'kind' => 'proxy',
                'config' => $config,
                'source_hash' => $sourceHash,
            ],
        );

        echo 'seeded';
        PHP;

    $result = $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
}
