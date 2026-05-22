<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('lists tables for a database connection from the control node through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    $slug = 'e2e-db-tables-'.strtolower(bin2hex(random_bytes(3)));
    $dbPath = '/tmp/'.$slug.'.sqlite';

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway', 'dev']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eInstallCurrentCheckoutOrbitWrapper($topology, 'dev');

        e2eRestartGatewayApi($topology, 'database-tables');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('control'),
            $config->controlUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );
        e2eGrantNodeAccess($topology);

        // Create a SQLite file with a table on the dev app node
        $topology->ssh(
            'dev',
            sprintf(
                'sqlite3 %s "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);"',
                escapeshellarg($dbPath),
            ),
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
            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($seedPhp),
            timeoutSeconds: 120,
        );

        $result = $topology->ssh(
            'control',
            sprintf(
                'cd %s && orbit database:tables %s --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($slug),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $rows = $payload['success']['data']['rows'] ?? [];

        expect($result->successful())->toBeTrue()
            ->and($rows)->toBeArray()
            ->and(array_column($rows, 'name'))->toContain('users');
    } finally {
        $slugValue = var_export($slug, true);
        $cleanupPhp = "\\App\\Models\\DatabaseConnection::query()->where('slug', {$slugValue})->delete(); echo 'cleaned';";

        $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
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
