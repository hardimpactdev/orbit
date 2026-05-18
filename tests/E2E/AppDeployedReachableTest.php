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
 * Asserts that an `app:new --environment=production` + `deploy:run` workflow lands
 * a working application that responds with a 200 + marker string when
 * requested by hostname from the control node.
 *
 * Depends on the gateway DNS provisioning plan
 * (`docs/superpowers/plans/2026-05-16-gateway-dns-provisioning.md`).
 */
it('serves a deployed production app from the control over its TLD hostname', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Blank);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'app-deployed-reachable');
    $bundle = null;
    $passed = false;
    $appName = 'e2e-deploy-'.strtolower(bin2hex(random_bytes(3)));
    $marker = 'orbit-e2e-deploy-marker-'.bin2hex(random_bytes(3));

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionControlFromBlank($provider, $run, $bundle, $config, $key);
        [$gateway] = e2eProvisionGatewayThroughNodeNew($provider, $run, $config, $control, $key);
        [$app] = e2eProvisionAppThroughNodeNew($provider, $run, $config, $control, $key, 'app-prod-1', 'production');

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();
        $appIp = $app->waitForIpv4();

        E2ENetwork::routeWireGuardPeer($control, '10.6.0.2', $gatewayIp, '10.6.0.3');
        E2ENetwork::routeWireGuardPeer($control, '10.6.0.4', $appIp, '10.6.0.3');
        E2ENetwork::routeWireGuardPeer($gateway, '10.6.0.3', $controlIp, '10.6.0.2');
        E2ENetwork::routeWireGuardPeer($gateway, '10.6.0.4', $appIp, '10.6.0.2');
        E2ENetwork::routeWireGuardPeer($app, '10.6.0.2', $gatewayIp, '10.6.0.4');
        E2ENetwork::routeWireGuardPeer($app, '10.6.0.3', $controlIp, '10.6.0.4');

        e2eGrantNodeAccessOnGateway($gateway, $key, serving: 'app-prod-1');

        E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            "cd /home/orbit/orbit && php artisan orbit:internal:bootstrap-gateway-local gateway-1 10.6.0.2 --public-host={$gatewayIp}",
            timeoutSeconds: 600,
        );

        $appPath = "/home/{$appName}/app";

        E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            sprintf(
                'cd /home/%s/orbit && orbit app:new %s --node=app-prod-1 --domain=%s --json',
                $config->controlUser,
                escapeshellarg($appName),
                escapeshellarg("{$appName}.app"),
            ),
            timeoutSeconds: 600,
        );

        E2ECommand::ssh(
            $app,
            $config->bootstrapUser,
            $key,
            sprintf(
                'sudo install -d -m 755 -o orbit -g orbit %s && sudo tee %s >/dev/null <<<%s',
                escapeshellarg("{$appPath}/public"),
                escapeshellarg("{$appPath}/public/index.php"),
                escapeshellarg("<?php echo '{$marker}';"),
            ),
            timeoutSeconds: 60,
        );

        E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg(<<<PHP
\App\Models\Node::query()->where('name', 'app-prod-1')->update(['tld' => 'app']);
app(\App\Services\Dns\DnsmasqReconciler::class)->reconcile();
echo 'seeded';
PHP),
            timeoutSeconds: 120,
        );

        E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            sprintf(
                'cd /home/%s/orbit && orbit deploy:step-add %s %s --title=%s --json',
                $config->controlUser,
                escapeshellarg($appName),
                escapeshellarg('true'),
                escapeshellarg('No-op deployment marker'),
            ),
            timeoutSeconds: 600,
        );

        E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit deploy:run {$appName} --json",
            timeoutSeconds: 600,
        );

        E2EReachability::assertHttpReachable(
            control: $control,
            controlUser: $config->controlUser,
            key: $key,
            url: "https://{$appName}.app/",
            expectedStatus: 200,
        );

        E2EReachability::assertHttpResponseContains(
            control: $control,
            controlUser: $config->controlUser,
            key: $key,
            url: "https://{$appName}.app/",
            marker: $marker,
        );

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});
