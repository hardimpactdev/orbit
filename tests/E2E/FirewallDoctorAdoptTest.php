<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyFactory;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyUnavailable;

it('adopts observed UFW rules into the gateway registry', function (): void {
    try {
        $lease = E2ETopologyFactory::fromEnvironment()
            ->requireCapabilities(E2ETopologyCapabilities::vm())
            ->require(E2ETopologyKind::ControlGatewayDev);
    } catch (E2ETopologyUnavailable $exception) {
        test()->markTestSkipped($exception->getMessage());
    }

    $topology = new E2ETopologyHarness($lease)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        $gatewayCheckout = $topology->checkout('gateway');

        // Install UFW on dev node and set up baseline rules
        $topology->ssh(
            'dev',
            'sudo apt-get update -qq && sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ufw && sudo ufw --force reset && sudo ufw default deny incoming && sudo ufw default allow outgoing && sudo ufw allow from 10.6.0.0/24 to any port 5173 proto tcp comment "orbit:local-vite" && sudo ufw --force enable',
            timeoutSeconds: 180,
        );

        // Verify UFW is running and has the rule we set up
        $ufwStatus = $topology->ssh('dev', 'sudo ufw status numbered', timeoutSeconds: 60);
        expect($ufwStatus->successful())->toBeTrue();
        $ufwOutput = trim($ufwStatus->output());
        expect($ufwOutput)->toContain('5173');

        // Update node record to active status so doctor can introspect it
        $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan tinker --execute=%s',
                escapeshellarg($gatewayCheckout),
                escapeshellarg('$node = \App\Models\Node::query()->where("name", "app-dev-1")->first(); if ($node) { $node->update(["platform" => "ubuntu", "status" => "active"]); echo "updated"; } else { echo "not found"; }'),
            ),
            timeoutSeconds: 120,
        );

        // Run doctor in adopt mode to discover and adopt UFW rules
        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan doctor --family=firewall_rule --adopt --json',
                escapeshellarg($gatewayCheckout),
            ),
            timeoutSeconds: 180,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['doctor']['mode'])->toBe('adopt');

        $adoptedCount = $payload['success']['data']['doctor']['summary']['adopted'] ?? 0;
        expect($adoptedCount)->toBeGreaterThanOrEqual(1);

        // Verify the adopted rule exists in the registry
        $verifyResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan tinker --execute=%s',
                escapeshellarg($gatewayCheckout),
                escapeshellarg('echo \App\Models\FirewallRule::query()->where("name", "orbit:local-vite")->first() ? "found" : "missing";'),
            ),
            timeoutSeconds: 120,
        );

        expect(trim($verifyResult->output()))->toContain('found');
    } finally {
        // Clean up UFW on dev node
        $topology->ssh('dev', 'sudo ufw --force reset && sudo apt-get remove -y -qq ufw', timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-control-gateway-dev');
