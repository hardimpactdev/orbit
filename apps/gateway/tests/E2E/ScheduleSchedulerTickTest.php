<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('dispatches app-node schedules from the gateway scheduler tick', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);
    $scheduleName = 'e2e-scheduler-'.strtolower(bin2hex(random_bytes(3)));
    $scheduleKey = "app:e2e-scheduler:{$scheduleName}";

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway', 'dev']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'schedule-scheduler-tick');
        E2EGatewayApi::waitForGatewayApi($topology->instance('operator'), $config->operatorUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        scheduleSchedulerSeedGatewayIntent($topology, $scheduleName, $scheduleKey);

        $tick = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && orbit orbit-scheduler --once',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );

        expect($tick->successful())->toBeTrue();
        expect($tick->output())->toContain('due=1 executed=1');

        $state = scheduleSchedulerGatewayState($topology, $scheduleKey);

        expect($state['run_count'])->toBe(1)
            ->and($state['latest_status'])->toBe('completed')
            ->and($state['latest_stdout'])->toContain('scheduler-e2e-ran')
            ->and($state['gateway_heartbeat'])->toBeTrue();
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function scheduleSchedulerSeedGatewayIntent($topology, string $scheduleName, string $scheduleKey): void
{
    $scheduleNameValue = var_export($scheduleName, true);
    $scheduleKeyValue = var_export($scheduleKey, true);

    $php = <<<PHP
\$node = \\App\\Models\\Node::query()->where('name', 'app-dev-1')->firstOrFail();
\$app = \\App\\Models\\App::query()->updateOrCreate(
    ['name' => 'e2e-scheduler'],
    [
        'node_id' => \$node->id,
        'path' => '/home/orbit/orbit',
        'document_root' => 'public',
        'php_version' => '8.5',
        'adopted' => true,
    ],
);

\\App\\Models\\Schedule::query()->updateOrCreate(
    ['schedule_key' => {$scheduleKeyValue}],
    [
        'name' => {$scheduleNameValue},
        'scope' => 'app',
        'app_id' => \$app->id,
        'node_id' => null,
        'target_name' => \$app->name,
        'interval' => 'every minute',
        'timezone' => 'UTC',
        'execution_type' => 'command',
        'execution_value' => 'echo scheduler-e2e-ran',
        'enabled' => true,
        'status' => 'expected',
    ],
);

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}

/**
 * @return array{run_count: int, latest_status: string|null, latest_stdout: string|null, gateway_heartbeat: bool}
 */
function scheduleSchedulerGatewayState($topology, string $scheduleKey): array
{
    $scheduleKeyValue = var_export($scheduleKey, true);

    $php = <<<PHP
\$run = \\App\\Models\\ScheduleRun::query()
    ->where('schedule_key', {$scheduleKeyValue})
    ->latest('id')
    ->first();
\$state = \\App\\Models\\SchedulerState::query()
    ->whereHas('node', fn (\$query) => \$query->where('name', 'gateway'))
    ->first();

echo json_encode([
    'run_count' => \\App\\Models\\ScheduleRun::query()->where('schedule_key', {$scheduleKeyValue})->count(),
    'latest_status' => \$run?->status,
    'latest_stdout' => \$run?->stdout,
    'gateway_heartbeat' => \$state?->heartbeat_at !== null,
], JSON_THROW_ON_ERROR);
PHP;

    $result = $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );

    /** @var array{run_count: int, latest_status: string|null, latest_stdout: string|null, gateway_heartbeat: bool} $state */
    $state = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

    return $state;
}
