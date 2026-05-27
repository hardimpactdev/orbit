<?php

declare(strict_types=1);

use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EImage;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EProvisioningBundle;
use App\E2E\Support\E2ERun;
use App\E2E\Support\IncusProvider;
use App\E2E\Support\ProviderPool;

pest()->group('e2e-provision');

it('NodeNewWireGuard enrolls the first gateway from base VMs', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Base);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'node-new-gateway');
    $bundle = null;
    $passed = false;

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionControlFromBase($provider, $run, $bundle, $config, $key);
        [$gateway, $payload] = e2eProvisionGatewayThroughNodeNew($provider, $run, $config, $control, $key, useWireGuardGatewayUrl: false);

        [$controlIp, $gatewayIp] = e2eProvisionStep('resolve VM IPv4 addresses', fn () => [
            $control->waitForIpv4(),
            $gateway->waitForIpv4(),
        ]);

        expect($controlIp)->not->toBe($gatewayIp);

        $command = "cd /home/{$config->controlUser}/orbit && php artisan node:new gateway-1 --role=gateway --host={$gatewayIp} --user={$config->bootstrapUser} --control-name=control-1 --json";
        expect($payload['success']['data']['node']['name'])->toBe('gateway-1')
            ->and($payload['success']['data']['node']['role'])->toBe('gateway')
            ->and($payload['success']['data']['local_control_node']['name'])->toBe('control-1');

        $php = <<<'PHP'
echo json_encode(\App\Models\Node::query()->orderBy('name')->pluck('name')->all(), JSON_THROW_ON_ERROR);
PHP;

        $registry = e2eProvisionStep('assert control registry', fn () => E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && php artisan tinker --execute=".escapeshellarg($php),
        ));

        expect($registry->output())->toContain('gateway-1')
            ->and($registry->output())->toContain('control-1');

        [$gatewayInstall, $gatewayVersion] = e2eProvisionStep('assert gateway install', fn () => [
            $gateway->exec('test -d /home/orbit/orbit && test -f /home/orbit/orbit/artisan'),
            $gateway->exec("sudo -iu orbit bash -lc 'orbit --version >/dev/null'"),
        ]);

        expect($gatewayInstall->successful())->toBeTrue($gatewayInstall->errorOutput())
            ->and($gatewayVersion->successful())->toBeTrue($gatewayVersion->output().$gatewayVersion->errorOutput());

        $gatewayWireGuard = e2eProvisionStep('assert gateway WireGuard interface', fn () => [
            $gateway->exec("! sudo grep -q '^ListenPort = 51820$' /etc/wireguard/wg-orbit.conf"),
            $gateway->exec("ip -o address show dev wg-orbit | grep -F '10.6.0.2/24'"),
            $gateway->exec('systemctl is-enabled wg-quick@wg-orbit'),
            $gateway->exec('docker exec wg-easy wg show wg0 listen-port | grep -Fx 51820'),
        ]);

        expect($gatewayWireGuard[0]->successful())->toBeTrue($gatewayWireGuard[0]->output().$gatewayWireGuard[0]->errorOutput())
            ->and($gatewayWireGuard[1]->successful())->toBeTrue($gatewayWireGuard[1]->output().$gatewayWireGuard[1]->errorOutput())
            ->and($gatewayWireGuard[2]->successful())->toBeTrue($gatewayWireGuard[2]->output().$gatewayWireGuard[2]->errorOutput())
            ->and($gatewayWireGuard[3]->successful())->toBeTrue($gatewayWireGuard[3]->output().$gatewayWireGuard[3]->errorOutput());

        $gatewayPeers = e2eProvisionStep('assert gateway WireGuard peers', fn () => gatewayWireGuardPeers($gateway));

        expect($gatewayPeers)->toHaveCount(2)
            ->and($gatewayPeers['gateway-1']['role'])->toBe('gateway')
            ->and($gatewayPeers['gateway-1']['wireguard_address'])->toBe('10.6.0.2')
            ->and($gatewayPeers['gateway-1']['allowed_ips'])->toBe('10.6.0.2/32')
            ->and($gatewayPeers['gateway-1']['public_key'])->toBeString()->not->toBeEmpty()
            ->and($gatewayPeers['control-1']['role'])->toBe('control')
            ->and($gatewayPeers['control-1']['wireguard_address'])->toBe('10.6.0.3')
            ->and($gatewayPeers['control-1']['allowed_ips'])->toBe('10.6.0.3/32')
            ->and($gatewayPeers['control-1']['public_key'])->toBeString()->not->toBeEmpty();

        $rerun = e2eProvisionStep('rerun node:new gateway', fn () => E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            $command,
            timeoutSeconds: 1800,
        ));

        $rerunPayload = json_decode(trim($rerun->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $rerunPeers = e2eProvisionStep('assert WireGuard enrollment idempotence', fn () => gatewayWireGuardPeers($gateway));

        expect($rerunPayload['success']['data']['result']['action'])->toBe('converged')
            ->and($rerunPayload['success']['data']['local_onboarding']['wireguard'])->toBe('already_installed')
            ->and($rerunPeers)->toBe($gatewayPeers);

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});

/**
 * @return array<string, array{role: string, wireguard_address: string, public_key: string, allowed_ips: string|null}>
 */
function gatewayWireGuardPeers(E2EInstance $gateway): array
{
    $php = <<<'PHP'
$peers = \Illuminate\Support\Facades\DB::table('nodes')
    ->join('wireguard_peers', 'nodes.id', '=', 'wireguard_peers.node_id')
    ->orderBy('nodes.name')
    ->get([
        'nodes.name',
        'nodes.role',
        'nodes.wireguard_address',
        'wireguard_peers.public_key',
        'wireguard_peers.allowed_ips',
    ])
    ->mapWithKeys(fn ($row) => [
        $row->name => [
            'role' => $row->role,
            'wireguard_address' => $row->wireguard_address,
            'public_key' => $row->public_key,
            'allowed_ips' => $row->allowed_ips,
        ],
    ])
    ->all();

echo json_encode($peers, JSON_THROW_ON_ERROR);
PHP;

    $encodedPhp = base64_encode($php);

    $result = $gateway->exec(
        'sudo -iu orbit bash -lc '.escapeshellarg('cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg("eval(base64_decode('{$encodedPhp}'));")),
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}
