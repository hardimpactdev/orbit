<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('updates a database connection from the operator node through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    $slug = 'e2e-db-upd-'.strtolower(bin2hex(random_bytes(3)));
    $updatedSlug = $slug.'-renamed';

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'database-update');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
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
            'operator',
            sprintf(
                'cd %s && orbit database:update %s --slug=%s --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($slug),
                escapeshellarg($updatedSlug),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['connection']['slug'])->toBe($updatedSlug);
    } finally {
        $updatedSlugValue = var_export($updatedSlug, true);
        $slugValue = var_export($slug, true);
        $cleanupPhp = "\\App\\Models\\DatabaseConnection::query()->whereIn('slug', [{$slugValue}, {$updatedSlugValue}])->delete(); echo 'cleaned';";

        $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($cleanupPhp),
            timeoutSeconds: 60,
        );

        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
