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
use App\E2E\Support\SshKeyPair;

pest()->group('e2e-provision');

function appNewProvisionGrantAccess(
    E2EInstance $gateway,
    SshKeyPair $key,
): void {
    $script = <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['control-1', 'app-dev-1'])
    ->pluck('id', 'name');

foreach (['control-1', 'app-dev-1'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\Illuminate\Support\Facades\DB::table('node_access')->updateOrInsert([
    'consumer_node_id' => $nodes->get('control-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
], [
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'granted';
PHP;

    E2ECommand::ssh(
        $gateway,
        'orbit',
        $key,
        'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('creates an app through provisioned gateway and converges real runtime artifacts', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Base);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'app-new-provision');
    $bundle = null;
    $passed = false;
    $name = 'e2e-app-'.strtolower(bin2hex(random_bytes(3)));

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionControlFromBase($provider, $run, $bundle, $config, $key);
        [$gateway] = e2eProvisionGatewayThroughNodeNew($provider, $run, $config, $control, $key);
        e2eProvisionAppThroughNodeNew($provider, $run, $config, $control, $key, 'app-dev-1', 'development', 'test');

        appNewProvisionGrantAccess($gateway, $key);

        $appNew = E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit app:new {$name} --node=app-dev-1 --json",
            timeoutSeconds: 600,
        );

        $payload = json_decode(trim($appNew->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['result']['action'])->toBe('created')
            ->and($payload['success']['data']['app']['name'])->toBe($name)
            ->and($payload['success']['data']['app']['node'])->toBe('app-dev-1')
            ->and($payload['success']['meta']['warnings'] ?? [])->toBe([]);

        $appRegister = E2ECommand::ssh(
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

        $registerPayload = json_decode(trim($appRegister->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($registerPayload['success']['data']['result']['action'])->toBe('converged')
            ->and($registerPayload['success']['data']['app']['name'])->toBe($name)
            ->and($registerPayload['success']['data']['app']['node'])->toBe('app-dev-1')
            ->and($registerPayload['success']['meta']['warnings'] ?? [])->toBe([]);

        $gatewayState = E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg("echo json_encode([
                'app' => \\App\\Models\\App::query()->where('name', '{$name}')->exists(),
                'route' => \\App\\Models\\ProxyRoute::query()->where('domain', '{$name}.test')->exists(),
                'app_wireguard_address' => \\App\\Models\\Node::query()->where('name', 'app-dev-1')->value('wireguard_address'),
                'processes' => \\App\\Models\\App::query()->where('name', '{$name}')->firstOrFail()->processes()->count(),
            ], JSON_THROW_ON_ERROR);"),
            timeoutSeconds: 120,
        );
        $state = json_decode(trim($gatewayState->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($state)->toMatchArray([
            'app' => true,
            'route' => true,
            'processes' => 0,
        ]);

        $appWireGuardAddress = $state['app_wireguard_address'] ?? null;

        expect($appWireGuardAddress)->toBeString()->not->toBe('');

        $artifactCommand = sprintf(
            'sudo test -d %s && sudo docker container inspect %s >/dev/null && sudo docker image inspect %s >/dev/null && sudo test -f %s && sudo test -f %s',
            escapeshellarg("/home/orbit/apps/{$name}"),
            escapeshellarg("orbit-app-{$name}"),
            escapeshellarg('dunglas/frankenphp:1-php8.5-bookworm'),
            escapeshellarg("/etc/orbit/apps/{$name}.ini"),
            escapeshellarg("/etc/caddy/sites/{$name}.test.caddy"),
        );

        E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            sprintf(
                'ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10 %s %s',
                escapeshellarg("{$config->bootstrapUser}@{$appWireGuardAddress}"),
                escapeshellarg($artifactCommand),
            ),
            timeoutSeconds: 120,
        );

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});
