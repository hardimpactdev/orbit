<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('removes a schedule from the control node through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'schedule-remove');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('control'),
            $config->controlUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );
        e2eGrantNodeAccess($topology);

        $appName = 'e2e-sched-rm-'.strtolower(bin2hex(random_bytes(3)));
        $scheduleName = 'e2e-rm-'.strtolower(bin2hex(random_bytes(3)));

        $seedPhp = <<<PHP
\$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
\$app = \App\Models\App::query()->updateOrCreate(
    ['name' => '{$appName}'],
    [
        'node_id' => \$node->id,
        'environment' => 'development',
        'path' => '/home/orbit/apps/{$appName}',
        'document_root' => 'public',
        'php_version' => '8.5',
        'adopted' => true,
    ],
);
\$state = \App\Models\SchedulerState::query()->updateOrCreate(
    ['node_id' => \$node->id],
    ['heartbeat_at' => now(), 'status' => 'running'],
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
        'execution_value' => 'echo hello',
        'enabled' => true,
        'status' => 'expected',
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
                'cd %s && php artisan schedule:remove %s --app=%s --force --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($scheduleName),
                escapeshellarg($appName),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['schedule']['name'])->toBe($scheduleName)
            ->and($payload['success']['data']['schedule']['status'])->toBe('removed')
            ->and($payload['success']['meta']['history_retained'])->toBeTrue();
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');
