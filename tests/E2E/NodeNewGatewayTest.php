<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2EImage;
use Tests\E2E\Support\E2EInstance;
use Tests\E2E\Support\E2ERun;
use Tests\E2E\Support\E2ETopologyCapabilities;
use Tests\E2E\Support\E2ETopologyFactory;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\IncusProvider;
use Tests\E2E\Support\ProviderPool;
use Tests\E2E\Support\SshKeyPair;

pest()->group('e2e-provision');

it('NodeNewWireGuard enrolls the first gateway from a prepared control topology', function (): void {
    $config = E2EConfig::fromEnvironment();
    $selection = (new ProviderPool([new IncusProvider($config)]))->select(E2EImage::Blank);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $topology = E2ETopologyFactory::fromEnvironment()
        ->requireCapabilities(E2ETopologyCapabilities::vm())
        ->require(E2ETopologyKind::ControlGatewayDevProd);
    $provider = $selection->provider();
    $run = E2ERun::start($provider, 'node-new-gateway');
    $passed = false;

    try {
        $key = $topology->sshKeyPair();
        $control = $topology->control();
        $gateway = e2eProvisionStep('launch blank gateway', fn () => $run->launchBlank('gateway'));
        $checkout = e2eProvisionStep('overlay current checkout on control', fn () => e2eCheckout($topology, ['control'])['control']);
        e2eProvisionStep('reset control checkout gateway state', fn () => resetControlCheckoutForFirstGateway($control, $config->controlUser, $key, $checkout));

        e2eProvisionStep('authorize gateway SSH', fn () => $gateway->authorizeSsh($config->bootstrapUser, $key));
        e2eProvisionStep('install control SSH private key', function () use ($config, $control, $key): void {
            $control->copyFileToInstance($key->privateKeyPath, "/home/{$config->controlUser}/.ssh/id_ed25519");
            $control->exec("chown {$config->controlUser}:{$config->controlUser} /home/{$config->controlUser}/.ssh/id_ed25519 && chmod 600 /home/{$config->controlUser}/.ssh/id_ed25519");
        });

        e2eProvisionStep('wait for SSH', function () use ($config, $control, $gateway, $key): void {
            $control->waitForSsh($config->controlUser, $key);
            $gateway->waitForSsh($config->bootstrapUser, $key);
        });

        [$controlIp, $gatewayIp] = e2eProvisionStep('resolve VM IPv4 addresses', fn () => [
            $control->waitForIpv4(),
            $gateway->waitForIpv4(),
        ]);

        expect($controlIp)->not->toBe($gatewayIp);

        $nodeNew = e2eProvisionStep('run node:new gateway', fn () => $control->ssh(
            $config->controlUser,
            $key,
            'cd '.escapeshellarg($checkout)." && php artisan node:new gateway-1 --role=gateway --host={$gatewayIp} --ssh-user={$config->bootstrapUser} --control-name=control-1 --json",
            timeoutSeconds: 1800,
        ));

        expect($nodeNew->successful())->toBeTrue($nodeNew->output().$nodeNew->errorOutput());

        $payload = json_decode(trim($nodeNew->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['node']['name'])->toBe('gateway-1')
            ->and($payload['success']['data']['node']['role'])->toBe('gateway')
            ->and($payload['success']['data']['local_control_node']['name'])->toBe('control-1');

        $php = <<<'PHP'
echo json_encode(\App\Models\Node::query()->orderBy('name')->pluck('name')->all(), JSON_THROW_ON_ERROR);
PHP;

        $registry = e2eProvisionStep('assert control registry', fn () => $control->ssh(
            $config->controlUser,
            $key,
            'cd '.escapeshellarg($checkout).' && php artisan tinker --execute='.escapeshellarg($php),
        ));

        expect($registry->successful())->toBeTrue($registry->output().$registry->errorOutput())
            ->and($registry->output())->toContain('gateway-1')
            ->and($registry->output())->toContain('control-1');

        [$gatewayInstall, $gatewayVersion] = e2eProvisionStep('assert gateway install', fn () => [
            $gateway->exec('test -d /home/orbit/orbit && test -f /home/orbit/orbit/artisan'),
            $gateway->exec("sudo -iu orbit bash -lc 'orbit --version | grep -F Orbit'"),
        ]);

        expect($gatewayInstall->successful())->toBeTrue($gatewayInstall->errorOutput())
            ->and($gatewayVersion->successful())->toBeTrue($gatewayVersion->output().$gatewayVersion->errorOutput());

        $gatewayWireGuard = e2eProvisionStep('assert gateway WireGuard interface', fn () => [
            $gateway->exec('sudo wg show wg-orbit listen-port | grep -Fx 51820'),
            $gateway->exec("ip -o address show dev wg-orbit | grep -F '10.6.0.2/24'"),
            $gateway->exec('systemctl is-enabled wg-quick@wg-orbit'),
        ]);

        expect($gatewayWireGuard[0]->successful())->toBeTrue($gatewayWireGuard[0]->output().$gatewayWireGuard[0]->errorOutput())
            ->and($gatewayWireGuard[1]->successful())->toBeTrue($gatewayWireGuard[1]->output().$gatewayWireGuard[1]->errorOutput())
            ->and($gatewayWireGuard[2]->successful())->toBeTrue($gatewayWireGuard[2]->output().$gatewayWireGuard[2]->errorOutput());

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

        $rerun = e2eProvisionStep('rerun node:new gateway', fn () => $control->ssh(
            $config->controlUser,
            $key,
            'cd '.escapeshellarg($checkout)." && php artisan node:new gateway-1 --role=gateway --host={$gatewayIp} --ssh-user={$config->bootstrapUser} --control-name=control-1 --json",
            timeoutSeconds: 1800,
        ));

        expect($rerun->successful())->toBeTrue($rerun->output().$rerun->errorOutput());

        $rerunPayload = json_decode(trim($rerun->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $rerunPeers = e2eProvisionStep('assert WireGuard enrollment idempotence', fn () => gatewayWireGuardPeers($gateway));

        expect($rerunPayload['success']['data']['result']['action'])->toBe('converged')
            ->and($rerunPayload['success']['data']['local_onboarding']['wireguard'])->toBe('already_installed')
            ->and($rerunPeers)->toBe($gatewayPeers);

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run, topology: $topology);
    }
});

function resetControlCheckoutForFirstGateway(
    E2EInstance $control,
    string $controlUser,
    SshKeyPair $key,
    string $checkout,
): void {
    $php = <<<'PHP'
\App\Models\Node::query()->where('role', '!=', 'control')->delete();
\App\Models\LocalGatewaySettings::query()->delete();
PHP;

    $result = $control->ssh(
        $controlUser,
        $key,
        'cd '.escapeshellarg($checkout).' && php artisan tinker --execute='.escapeshellarg($php),
    );

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
}

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
