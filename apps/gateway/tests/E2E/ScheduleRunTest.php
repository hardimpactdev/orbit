<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('runs a schedule from the operator node through the gateway api and records history', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'schedule-run');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );
        e2eGrantNodeAccess($topology);

        $appName = 'e2e-sched-run-'.strtolower(bin2hex(random_bytes(3)));
        $scheduleName = 'e2e-run-'.strtolower(bin2hex(random_bytes(3)));

        $seedPhp = <<<PHP
\$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
\$app = \App\Models\App::query()->updateOrCreate(
    ['name' => '{$appName}'],
    [
        'node_id' => \$node->id,
        'path' => '/home/orbit/apps/{$appName}',
        'document_root' => 'public',
        'php_version' => '8.5',
        'adopted' => true,
    ],
);
\App\Models\Schedule::query()->updateOrCreate(
    ['schedule_key' => "app:{$appName}:{$scheduleName}"],
    [
        'name' => '{$scheduleName}',
        'scope' => 'app',
        'app_id' => \$app->id,
        'node_id' => null,
        'target_name' => \$app->name,
        'interval' => 'every minute',
        'timezone' => 'UTC',
        'execution_type' => 'command',
        'execution_value' => 'echo e2e-schedule-ran',
        'enabled' => true,
        'status' => 'expected',
    ],
);
echo 'seeded';
PHP;

        $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($seedPhp),
            timeoutSeconds: 120,
        );

        $topology->ssh(
            'dev',
            'mkdir -p '.escapeshellarg("/home/orbit/apps/{$appName}"),
            timeoutSeconds: 60,
        );

        $result = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit schedule:run %s --app=%s --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($scheduleName),
                escapeshellarg($appName),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['run']['status'])->toBe('completed')
            ->and($payload['success']['data']['run']['exit_code'])->toBe(0)
            ->and($payload['success']['data']['output']['stdout'])->toContain('e2e-schedule-ran');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');
