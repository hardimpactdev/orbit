<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('executes a database query from the control node through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    $slug = 'e2e-db-query-'.strtolower(bin2hex(random_bytes(3)));
    $dbPath = '/tmp/'.$slug.'.sqlite';

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway', 'dev']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        // Redirect the orbit binary on the dev node to the current checkout so
        // the gateway's RemoteShell can invoke database:query-local. We replace
        // /usr/local/bin/orbit directly via docker cp to avoid running a login
        // shell (which hangs in the Docker topology environment).
        $devCheckout = $topology->checkout('dev');
        $orbitScript = "#!/usr/bin/env bash\nset -e\nexec php ".escapeshellarg("{$devCheckout}/artisan").' "$@"'."\n";
        $tmpScript = tempnam(sys_get_temp_dir(), 'orbit-dev-');
        file_put_contents($tmpScript, $orbitScript);
        chmod($tmpScript, 0755);
        $topology->instance('dev')->copyFileToInstance($tmpScript, '/usr/local/bin/orbit');
        @unlink($tmpScript);

        e2eRestartGatewayApi($topology, 'database-query');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('control'),
            $config->controlUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );
        e2eGrantNodeAccess($topology);

        // Create an empty SQLite file on the dev app node
        $topology->ssh(
            'dev',
            sprintf('touch %s', escapeshellarg($dbPath)),
            timeoutSeconds: 30,
        );

        // Register the connection on the gateway pointing at the dev node
        $slugValue = var_export($slug, true);
        $dbPathValue = var_export($dbPath, true);
        $seedPhp = <<<PHP
\$node = \\App\\Models\\Node::query()->where('name', 'app-dev-1')->firstOrFail();
\\App\\Models\\DatabaseConnection::query()->updateOrCreate(
    ['slug' => {$slugValue}],
    [
        'node_id' => \$node->id,
        'driver' => 'sqlite',
        'path' => {$dbPathValue},
    ],
);
echo 'seeded';
PHP;

        $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg($seedPhp),
            timeoutSeconds: 120,
        );

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan database:query %s --sql=%s --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($slug),
                escapeshellarg('select 1 as value'),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data'])->toBeArray()
            ->and($payload['success']['meta']['connection'])->toBe($slug);
    } finally {
        $slugValue = var_export($slug, true);
        $cleanupPhp = "\\App\\Models\\DatabaseConnection::query()->where('slug', {$slugValue})->delete(); echo 'cleaned';";

        $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg($cleanupPhp),
            timeoutSeconds: 60,
        );

        $topology->ssh(
            'dev',
            sprintf('rm -f %s', escapeshellarg($dbPath)),
            timeoutSeconds: 30,
        );

        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-control-gateway-dev');
