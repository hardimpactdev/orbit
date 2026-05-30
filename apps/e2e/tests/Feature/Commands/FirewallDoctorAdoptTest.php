<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyKind;

it('adopts observed UFW rules into the gateway registry', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);

    $gatewayWireGuardIp = $topology->lease()->gatewayApiIp();
    $gatewayLanIp = $topology->instance('gateway')->waitForIpv4();
    $devLanIp = $topology->instance('dev')->waitForIpv4();
    $wireGuardCidr = firewallDoctorAdoptWireGuardCidr($gatewayWireGuardIp);

    try {
        $gatewayCheckout = $topology->checkout('gateway');

        $topology->ssh(
            'dev',
            sprintf(
                'command -v ufw >/dev/null 2>&1 || { echo "ufw is missing from the prepared Incus artifact. Rebuild the base image and prepared topology." >&2; exit 1; }; sudo ufw --force reset && sudo ufw default deny incoming && sudo ufw default allow outgoing && sudo ufw allow from %s to any port 22 proto tcp comment "orbit:e2e-gateway-wg-ssh" && sudo ufw allow from %s to any port 22 proto tcp comment "orbit:e2e-gateway-lan-ssh" && sudo ufw allow from %s to any port 5173 proto tcp comment "orbit:local-vite" && sudo ufw --force enable',
                escapeshellarg("{$gatewayWireGuardIp}/32"),
                escapeshellarg("{$gatewayLanIp}/32"),
                escapeshellarg($wireGuardCidr),
            ),
            timeoutSeconds: 180,
        );

        $ufwStatus = $topology->ssh('dev', 'sudo ufw status numbered', timeoutSeconds: 60);
        expect($ufwStatus->successful())->toBeTrue()
            ->and(trim($ufwStatus->output()))->toContain('5173');

        $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php apps/gateway/artisan tinker --execute=%s',
                escapeshellarg($gatewayCheckout),
                escapeshellarg('$node = \App\Models\Node::query()->where("name", "app-dev-1")->first(); if ($node) { \App\Models\FirewallRule::query()->where("node_id", $node->id)->delete(); $node->update(["platform" => "ubuntu", "status" => "active", "host" => "'.$devLanIp.'", "wireguard_address" => null]); echo "updated"; } else { echo "not found"; }'),
            ),
            timeoutSeconds: 120,
        );

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit doctor --node=app-dev-1 --family=firewall_rule --adopt --json',
                escapeshellarg($gatewayCheckout),
            ),
            timeoutSeconds: 180,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['doctor']['mode'])->toBe('adopt')
            ->and($payload['success']['data']['doctor']['summary']['adopted'] ?? 0)->toBeGreaterThanOrEqual(1);

        $verifyResult = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php apps/gateway/artisan tinker --execute=%s',
                escapeshellarg($gatewayCheckout),
                escapeshellarg('echo \App\Models\FirewallRule::query()->where("name", "local-vite")->where("reason", "orbit:local-vite")->first() ? "found" : "missing";'),
            ),
            timeoutSeconds: 120,
        );

        expect(trim($verifyResult->output()))->toContain('found');
    } finally {
        $topology->ssh('dev', 'if command -v ufw >/dev/null 2>&1; then sudo ufw --force disable && sudo ufw --force reset; fi', timeoutSeconds: 120);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function firewallDoctorAdoptWireGuardCidr(string $gatewayWireGuardIp): string
{
    $parts = explode('.', $gatewayWireGuardIp);

    if (count($parts) !== 4) {
        throw new RuntimeException("Invalid gateway WireGuard IP [{$gatewayWireGuardIp}].");
    }

    return "{$parts[0]}.{$parts[1]}.0.0/24";
}
