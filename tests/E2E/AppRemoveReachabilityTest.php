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

pest()->group('e2e-feature', 'e2e-feature-reachability');

/**
 * Verifies the reachability regression on `app:remove`: a deployed app must
 * stop responding by hostname after removal. We lock in the contract as 404
 * (Caddy default for an unconfigured host on a node with no matching site).
 *
 * Depends on the gateway DNS provisioning plan
 * (`docs/superpowers/plans/2026-05-16-gateway-dns-provisioning.md`).
 */
it('stops serving a removed development app from the control over its TLD hostname', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Blank);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'app-remove-reachability');
    $bundle = null;
    $passed = false;
    $name = 'e2e-app-rm-'.strtolower(bin2hex(random_bytes(3)));

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionControlFromBlank($provider, $run, $bundle, $config, $key);
        [$gateway] = e2eProvisionGatewayThroughNodeNew($provider, $run, $config, $control, $key);
        [$app] = e2eProvisionAppThroughNodeNew($provider, $run, $config, $control, $key, 'app-dev-1', 'development', 'test');

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();
        $appIp = $app->waitForIpv4();

        E2ENetwork::routeWireGuardPeer($control, '10.6.0.2', $gatewayIp, '10.6.0.3');
        E2ENetwork::routeWireGuardPeer($gateway, '10.6.0.3', $controlIp, '10.6.0.2');
        E2ENetwork::routeWireGuardPeer($gateway, '10.6.0.4', $appIp, '10.6.0.2');
        E2ENetwork::routeWireGuardPeer($app, '10.6.0.2', $gatewayIp, '10.6.0.4');

        E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            "cd /home/orbit/orbit && php artisan orbit:internal:bootstrap-gateway-local gateway-1 10.6.0.2 --public-host={$gatewayIp}",
            timeoutSeconds: 600,
        );

        E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit app:new {$name} --node=app-dev-1 --json",
            timeoutSeconds: 600,
        );

        E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            sprintf(
                'cd /home/%s/orbit && orbit app:register %s --node=app-dev-1 --path=%s --json',
                $config->controlUser,
                escapeshellarg($name),
                escapeshellarg("/home/orbit/apps/{$name}"),
            ),
            timeoutSeconds: 600,
        );

        E2ECommand::ssh(
            $app,
            $config->bootstrapUser,
            $key,
            sprintf(
                'sudo install -d -m 755 -o orbit -g orbit %s && sudo tee %s >/dev/null <<<%s',
                escapeshellarg("/home/orbit/apps/{$name}/public"),
                escapeshellarg("/home/orbit/apps/{$name}/public/index.php"),
                escapeshellarg("<?php echo 'orbit-e2e-app-remove';"),
            ),
            timeoutSeconds: 60,
        );

        E2EReachability::assertHttpReachable(
            control: $control,
            controlUser: $config->controlUser,
            key: $key,
            url: "https://{$name}.test/",
            expectedStatus: 200,
        );

        E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit app:remove {$name} --node=app-dev-1 --json",
            timeoutSeconds: 600,
        );

        E2EReachability::assertHttpReachable(
            control: $control,
            controlUser: $config->controlUser,
            key: $key,
            url: "https://{$name}.test/",
            expectedStatus: 404,
        );

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});
