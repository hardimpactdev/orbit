<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('detaches a database connection from an app from the operator node through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    $slug = 'e2e-db-detach-'.strtolower(bin2hex(random_bytes(3)));
    $appName = 'e2e-detach-app-'.strtolower(bin2hex(random_bytes(3)));

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'database-detach');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );
        e2eGrantNodeAccess($topology);

        $slugValue = var_export($slug, true);
        $appNameValue = var_export($appName, true);
        $seedPhp = <<<PHP
\$node = \\App\\Models\\Node::query()->where('name', 'app-dev-1')->firstOrFail();
\$app = \\App\\Models\\App::query()->updateOrCreate(
    ['name' => {$appNameValue}],
    [
        'node_id' => \$node->id,
        'path' => '/home/orbit/apps/{$appName}',
        'document_root' => 'public',
        'php_version' => '8.5',
        'adopted' => true,
    ],
);
\$connection = \\App\\Models\\DatabaseConnection::query()->updateOrCreate(
    ['slug' => {$slugValue}],
    [
        'node_id' => \$node->id,
        'driver' => 'sqlite',
        'path' => '/srv/docs/database.sqlite',
    ],
);
\\App\\Models\\DatabaseConnectionTarget::query()->updateOrCreate(
    ['database_connection_id' => \$connection->id, 'app_id' => \$app->id],
    ['env_prefix' => 'DB'],
);
echo 'seeded';
PHP;

        $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($seedPhp),
            timeoutSeconds: 120,
        );

        $result = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit database:detach %s --app=%s --env-prefix=DB --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($slug),
                escapeshellarg($appName),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['result']['action'])->toBe('detached')
            ->and($payload['success']['data']['result']['connection'])->toBe($slug)
            ->and($payload['success']['data']['result']['target'])->toBe($appName);
    } finally {
        $slugValue = var_export($slug, true);
        $appNameValue = var_export($appName, true);
        $cleanupPhp = <<<PHP
\\App\\Models\\DatabaseConnection::query()->where('slug', {$slugValue})->delete();
\\App\\Models\\App::query()->where('name', {$appNameValue})->delete();
echo 'cleaned';
PHP;

        $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($cleanupPhp),
            timeoutSeconds: 60,
        );

        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
