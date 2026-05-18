<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('reads schedule run logs from the control node through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway', 'dev']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            'schedule-logs',
            $topology->checkout('gateway'),
            gatewayIp: $gatewayApiIp,
        );
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('control'),
            $config->controlUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );
        e2eGrantNodeAccess($topology);

        $appName = 'e2e-sched-logs-'.strtolower(bin2hex(random_bytes(3)));
        $scheduleName = 'e2e-logs-'.strtolower(bin2hex(random_bytes(3)));
        $scheduleKey = "app:{$appName}:{$scheduleName}";

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
\App\Models\Schedule::query()->updateOrCreate(
    ['schedule_key' => '{$scheduleKey}'],
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
\App\Models\ScheduleRun::query()->create([
    'node_id' => \$node->id,
    'schedule_key' => '{$scheduleKey}',
    'status' => 'completed',
    'exit_code' => 0,
    'stdout' => "e2e-logs-output\n",
    'stderr' => '',
    'started_at' => now(),
    'finished_at' => now()->addSeconds(1),
    'duration_ms' => 1000,
]);
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
                'cd %s && php artisan schedule:logs %s --app=%s --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($scheduleName),
                escapeshellarg($appName),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['run']['status'])->toBe('completed')
            ->and($payload['success']['data']['output']['stdout'])->toContain('e2e-logs-output')
            ->and($payload['success']['meta']['lines'])->toBe(100)
            ->and($payload['success']['meta']['truncated'])->toBeFalse();
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway-appdev', 'e2e-feature-control-gateway-dev');
