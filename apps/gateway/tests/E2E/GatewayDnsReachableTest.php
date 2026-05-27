<?php

declare(strict_types=1);

use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EImage;
use App\E2E\Support\E2ENetwork;
use App\E2E\Support\E2EProvisioningBundle;
use App\E2E\Support\E2EReachability;
use App\E2E\Support\E2ERun;
use App\E2E\Support\IncusProvider;
use App\E2E\Support\ProviderPool;

pest()->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-reachability');

/**
 * Smallest credible reachability test. Provisions a gateway with the new DNS
 * bootstrap path, then asserts from the operator node that:
 *   1. `<gateway-name>.<gateway-tld>` resolves over WG via the gateway DNS.
 *   2. `https://<gateway-wg-ip>/` returns 200 over WG. Gateway API TLS is
 *      currently issued for the WireGuard IP, which is also the stored gateway
 *      URL used by operator nodes.
 *
 * Depends on the gateway DNS provisioning plan
 * (`docs/superpowers/plans/2026-05-16-gateway-dns-provisioning.md`).
 */
it('resolves and serves the gateway TLD over wireguard', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Base);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'gateway-dns-reachable');
    $bundle = null;
    $passed = false;

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionControlFromBase($provider, $run, $bundle, $config, $key);
        [$gateway] = e2eProvisionGatewayThroughNodeNew($provider, $run, $config, $control, $key);

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();

        E2ENetwork::routeWireGuardPeer($control, '10.6.0.2', $gatewayIp, '10.6.0.3');
        E2ENetwork::routeWireGuardPeer($gateway, '10.6.0.3', $controlIp, '10.6.0.2');

        E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            "cd /home/orbit/orbit && php artisan orbit:internal:bootstrap-gateway-local gateway-1 10.6.0.2 --public-host={$gatewayIp}",
            timeoutSeconds: 600,
        );

        E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg(<<<'PHP'
\App\Models\Node::query()->where('name', 'gateway-1')->update(['tld' => 'gateway']);
app(\App\Services\Dns\DnsmasqReconciler::class)->reconcile();
echo 'tld-set';
PHP),
            timeoutSeconds: 60,
        );

        E2EReachability::assertDnsResolvesOverWg(
            control: $control,
            controlUser: $config->controlUser,
            key: $key,
            hostname: 'gateway-1.gateway',
            expectedIp: '10.6.0.2',
        );

        E2EReachability::assertHttpReachable(
            control: $control,
            controlUser: $config->controlUser,
            key: $key,
            url: 'https://10.6.0.2/',
            expectedStatus: 200,
        );

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});
