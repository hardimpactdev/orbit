<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('publishes the private orbit DNS tld from gateway proxy intent on Docker prepared topology', function (): void {
    metricsDnsDoctorPublishesRoute();
})->group('e2e-feature', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');

it('publishes the private orbit DNS tld from gateway proxy intent on Incus prepared topology', function (): void {
    metricsDnsDoctorPublishesRoute();
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');

function metricsDnsDoctorPublishesRoute(): void
{
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway, withGatewayApi: true)
        ->withCurrentCheckout(roles: ['gateway']);
    $checkout = escapeshellarg($topology->checkout('gateway'));

    try {
        e2eRestartGatewayApi($topology, 'metrics-dns-doctor');

        $state = metricsDnsDoctorSeedRouteIntent($topology, $checkout);
        $confPath = "{$state['config_root']}/dnsmasq.conf";

        metricsDnsDoctorRemovePublication($topology, $confPath);
        metricsDnsDoctorReconcile($topology, $checkout);

        $published = $topology->ssh(
            'gateway',
            'grep -Fx '.escapeshellarg("address=/orbit/{$state['wireguard_address']}").' '.escapeshellarg($confPath)
                .' && grep -Fx '.escapeshellarg('local=/orbit/').' '.escapeshellarg($confPath)
                .' && ! grep -F '.escapeshellarg('address=/metrics.orbit/').' '.escapeshellarg($confPath),
            timeoutSeconds: 60,
        );

        expect($published->successful())->toBeTrue($published->output().$published->errorOutput());
    } finally {
        $topology->cleanup();
    }
}

/**
 * @return array{config_root: string, wireguard_address: string}
 */
function metricsDnsDoctorSeedRouteIntent(E2ETopologyHarness $topology, string $checkout): array
{
    $script = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'gateway')->firstOrFail();
$config = \App\Services\Metrics\MetricsServiceRoute::config();
$route = new \App\Models\ProxyRoute([
    'node_id' => $node->id,
    'domain' => \App\Services\Metrics\MetricsServiceRoute::Domain,
    'owner_type' => 'router',
    'kind' => 'proxy',
    'config' => $config,
]);
$sourceHash = app(\App\Services\Proxy\ProxyRouteRenderer::class)->sourceHash($route);

\App\Models\ProxyRoute::query()->updateOrCreate(
    ['domain' => \App\Services\Metrics\MetricsServiceRoute::Domain],
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

echo json_encode([
    'config_root' => rtrim((string) config('orbit.paths.config_root'), '/'),
    'wireguard_address' => (string) $node->wireguard_address,
], JSON_THROW_ON_ERROR);
PHP;

    $result = $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

    /** @var array{config_root: string, wireguard_address: string} $state */
    $state = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

    return $state;
}

function metricsDnsDoctorRemovePublication(E2ETopologyHarness $topology, string $confPath): void
{
    $script = sprintf(
        <<<'SH'
if [ -f %1$s ]; then
    tmp="$(mktemp)"
    grep -v -e '^address=/orbit/' -e '^local=/orbit/' -e 'metrics\.orbit' %1$s > "$tmp" || true
    cat "$tmp" > %1$s
    rm -f "$tmp"
fi
SH,
        escapeshellarg($confPath),
    );

    $result = $topology->ssh('gateway', $script, timeoutSeconds: 60);

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
}

function metricsDnsDoctorReconcile(E2ETopologyHarness $topology, string $checkout): void
{
    $script = <<<'PHP'
app(\App\Services\Dns\DnsmasqReconciler::class)->reconcile();

echo 'reconciled';
PHP;

    $result = $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
}
