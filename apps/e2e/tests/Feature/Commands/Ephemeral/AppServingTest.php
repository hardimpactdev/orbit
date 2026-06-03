<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

it('serves an app created on a prepared app-dev topology', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true)
        ->withCurrentCheckout(roles: ['operator', 'gateway']);
    $operatorUser = $config->operatorUser;
    $appName = 'e2e-serve-'.strtolower(bin2hex(random_bytes(3)));
    $appPath = "/home/orbit/apps/{$appName}";
    $devWireGuardIp = '10.6.0.4';

    try {
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'app-serving');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );

        appServingGrantAccess($topology);
        appServingPrepareRedisProbe($topology);
        appServingAssertDoctorHealthy($topology, 'tool');

        $appNewResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit app:new %s --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($appName),
            ),
            timeoutSeconds: 180,
        );
        $appNewData = e2eJsonCommandResultData(e2eJsonCommandPayload($appNewResult->output()));

        expect($appNewData['app']['name'])->toBe($appName)
            ->and($appNewData['app']['node'])->toBe('app-dev-1');

        $indexPhp = "<?php\nhttp_response_code(200);\necho 'orbit-e2e-serving-ok';\n";
        $composerJson = json_encode([
            'name' => "orbit-e2e/{$appName}",
            'require' => (object) [],
            'config' => ['optimize-autoloader' => false],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $topology->ssh(
            'dev',
            sprintf(
                'sudo -u orbit bash -lc %s',
                escapeshellarg(implode(' && ', [
                    sprintf('mkdir -p %s', escapeshellarg("{$appPath}/public")),
                    sprintf('printf %%s %s > %s', escapeshellarg($indexPhp), escapeshellarg("{$appPath}/public/index.php")),
                    sprintf('printf %%s %s > %s', escapeshellarg($composerJson), escapeshellarg("{$appPath}/composer.json")),
                ])),
            ),
            timeoutSeconds: 60,
        );

        $composerInstallResult = $topology->ssh(
            'dev',
            sprintf(
                'sudo -u orbit bash -lc %s',
                escapeshellarg('cd '.escapeshellarg($appPath).' && HOME=/home/orbit /usr/local/bin/composer install --no-interaction --no-progress'),
            ),
            timeoutSeconds: 300,
        );

        expect($composerInstallResult->successful())->toBeTrue(
            "composer install on app-dev failed: {$composerInstallResult->output()}{$composerInstallResult->errorOutput()}"
        );

        appServingRestoreDoctorFamily($topology, 'app');
        appServingRestoreDoctorFamily($topology, 'proxy');

        $curlResult = $topology->ssh(
            'gateway',
            sprintf(
                'curl -fsSL --retry 10 --retry-delay 2 --retry-connrefused --connect-timeout 10 --max-time 30 --cacert /home/orbit/.config/orbit/ca/root.crt --resolve %s:443:%s https://%s/',
                escapeshellarg("{$appName}.test"),
                escapeshellarg($devWireGuardIp),
                escapeshellarg("{$appName}.test"),
            ),
            timeoutSeconds: 120,
        );

        expect($curlResult->successful())->toBeTrue(
            "curl of served app failed: {$curlResult->output()}{$curlResult->errorOutput()}"
        )
            ->and($curlResult->output())->toContain('orbit-e2e-serving-ok');

        $phpVersionResult = $topology->ssh(
            'dev',
            '/opt/orbit/php/8.5/bin/php -r "echo PHP_MAJOR_VERSION.\'.\'.PHP_MINOR_VERSION;"',
            timeoutSeconds: 30,
        );

        expect(trim($phpVersionResult->output()))->toBe('8.5');

        $composerVersionResult = $topology->ssh('dev', 'cd /home/orbit && HOME=/home/orbit /usr/local/bin/composer --version --no-interaction 2>&1', timeoutSeconds: 30);
        $laravelVersionResult = $topology->ssh('dev', 'cd /home/orbit && HOME=/home/orbit /usr/local/bin/laravel --version 2>&1', timeoutSeconds: 30);

        expect($composerVersionResult->output())->toContain('Composer')
            ->and($laravelVersionResult->output())->toContain('Laravel');
    } finally {
        $topology->ssh(
            'dev',
            'sudo rm -rf '.escapeshellarg($appPath)
                .' && sudo systemctl disable --now redis-server >/dev/null 2>&1 || true'
                .' && sudo rm -f /etc/systemd/system/redis-server.service /usr/local/bin/redis-server'
                .' && sudo systemctl daemon-reload >/dev/null 2>&1 || true',
            timeoutSeconds: 60,
        );
        $topology->cleanup();
    }
});

function appServingRestoreDoctorFamily(E2ETopologyHarness $topology, string $family): void
{
    $doctorResult = $topology->ssh(
        'operator',
        sprintf(
            'cd %s && orbit doctor --node=app-dev-1 --family=%s --restore --json',
            escapeshellarg($topology->checkout('operator')),
            escapeshellarg($family),
        ),
        timeoutSeconds: 900,
    );
    $doctorData = e2eJsonCommandData(e2eJsonCommandPayload($doctorResult->output()));

    expect($doctorData['doctor']['healthy'])->toBeTrue(
        "doctor --family={$family} --restore left the node unhealthy: ".json_encode($doctorData, JSON_PRETTY_PRINT)
    );
}

function appServingAssertDoctorHealthy(E2ETopologyHarness $topology, string $family, ?string $key = null): void
{
    $keyOption = $key === null ? '' : ' --key='.escapeshellarg($key);
    $doctorResult = $topology->ssh(
        'operator',
        sprintf(
            'cd %s && orbit doctor --node=app-dev-1 --family=%s%s --json',
            escapeshellarg($topology->checkout('operator')),
            escapeshellarg($family),
            $keyOption,
        ),
        timeoutSeconds: 900,
    );
    $doctorData = e2eJsonCommandData(e2eJsonCommandPayload($doctorResult->output()));

    expect($doctorData['doctor']['healthy'])->toBeTrue(
        "plain doctor --family={$family} check was unhealthy: ".json_encode($doctorData, JSON_PRETTY_PRINT)
    );
}

function appServingPrepareRedisProbe(E2ETopologyHarness $topology): void
{
    $redisServer = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

if [ "${1:-}" = "--version" ]; then
    echo 'Redis server v=7.2.0'
    exit 0
fi

exec sleep infinity
BASH;

    $service = <<<'SYSTEMD'
[Unit]
Description=Orbit E2E Redis probe service

[Service]
ExecStart=/usr/local/bin/redis-server
Restart=always

[Install]
WantedBy=multi-user.target
SYSTEMD;

    $topology->ssh(
        'dev',
        sprintf(
            'printf %%s %s | sudo tee /usr/local/bin/redis-server >/dev/null'
                .' && sudo chmod 0755 /usr/local/bin/redis-server'
                .' && printf %%s %s | sudo tee /etc/systemd/system/redis-server.service >/dev/null'
                .' && sudo systemctl daemon-reload'
                .' && sudo systemctl enable --now redis-server',
            escapeshellarg($redisServer),
            escapeshellarg($service),
        ),
        timeoutSeconds: 60,
    );
}

function appServingGrantAccess(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $script = <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['operator-1', 'app-dev-1'])
    ->pluck('id', 'name');

foreach (['operator-1', 'app-dev-1'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\Illuminate\Support\Facades\DB::table('node_access')->updateOrInsert([
    'consumer_node_id' => $nodes->get('operator-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
], [
    'permissions' => json_encode(['app:new'], JSON_THROW_ON_ERROR),
    'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'granted';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}
