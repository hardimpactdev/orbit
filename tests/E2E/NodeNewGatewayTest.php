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

it('provisions the first gateway from a prepared control topology', function (): void {
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
