<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('removes a database connection from the control node through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    $slug = 'e2e-db-rm-'.strtolower(bin2hex(random_bytes(3)));

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'database-remove');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('control'),
            $config->controlUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );
        e2eGrantNodeAccess($topology);

        $slugValue = var_export($slug, true);
        $seedPhp = <<<PHP
\$node = \\App\\Models\\Node::query()->where('name', 'app-dev-1')->firstOrFail();
\\App\\Models\\DatabaseConnection::query()->updateOrCreate(
    ['slug' => {$slugValue}],
    [
        'node_id' => \$node->id,
        'driver' => 'sqlite',
        'path' => '/srv/docs/database.sqlite',
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
                'cd %s && orbit database:remove %s --force --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($slug),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['result']['action'])->toBe('removed')
            ->and($payload['success']['data']['result']['connection'])->toBe($slug);
    } finally {
        $slugValue = var_export($slug, true);
        $cleanupPhp = "\\App\\Models\\DatabaseConnection::query()->where('slug', {$slugValue})->delete(); echo 'cleaned';";

        $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
            timeoutSeconds: 60,
        );

        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-control-gateway-dev');
