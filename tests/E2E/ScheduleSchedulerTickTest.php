<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('syncs app-node schedule intent and reports run history from a scheduler tick', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::ControlGatewayDev, withGatewayApi: true);
    $scheduleName = 'e2e-scheduler-'.strtolower(bin2hex(random_bytes(3)));
    $scheduleKey = "app:e2e-scheduler:{$scheduleName}";
    $hookPath = '/opt/orbit/schedules/hooks/'.hash('sha256', $scheduleKey).'.sh';

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway', 'dev']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            'schedule-scheduler-tick',
            $topology->checkout('gateway'),
            gatewayIp: $gatewayApiIp,
        );
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        scheduleSchedulerSeedGatewayIntent($topology, $scheduleName, $scheduleKey);
        scheduleSchedulerPrepareDevNode($topology, $gatewayApiIp, scheduleSchedulerGatewayRootCa($topology), $hookPath);

        $tick = $topology->ssh(
            'dev',
            sprintf(
                'cd %s && php artisan orbit-scheduler --once',
                escapeshellarg($topology->checkout('dev')),
            ),
            timeoutSeconds: 180,
        );

        expect($tick->successful())->toBeTrue();
        expect($tick->output())->toContain('due=1 executed=1');

        $state = scheduleSchedulerGatewayState($topology, $scheduleKey);

        expect($state['run_count'])->toBe(1)
            ->and($state['latest_status'])->toBe('completed')
            ->and($state['latest_stdout'])->toContain('scheduler-e2e-ran')
            ->and($state['scheduler_synced'])->toBeTrue();
    } finally {
        $topology->ssh('dev', 'sudo rm -f '.escapeshellarg($hookPath), timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-control-gateway-dev');

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
        'environment' => 'development',
        'path' => '/home/orbit/apps/e2e-scheduler',
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
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}

function scheduleSchedulerPrepareDevNode($topology, string $gatewayApiIp, string $gatewayRootCa, string $hookPath): void
{
    $gatewayApiIpValue = var_export($gatewayApiIp, true);
    $caPath = $topology->checkout('dev').'/storage/app/orbit/gateway-ca/orbit.crt';
    $caPathValue = var_export($caPath, true);

    $php = <<<PHP
\\App\\Models\\Node::query()->updateOrCreate(
    ['name' => 'app-dev-1'],
    [
        'role' => 'app',
        'environment' => 'development',
        'tld' => 'test',
        'host' => '10.6.0.4',
        'wireguard_address' => '10.6.0.4',
        'gateway_endpoint' => {$gatewayApiIpValue},
        'ssh_user' => 'orbit',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
        'is_local' => true,
    ],
);

\$settings = \\App\\Models\\LocalGatewaySettings::current();
\$settings->fill([
    'gateway_url' => 'https://'.{$gatewayApiIpValue},
    'gateway_wg_ip' => {$gatewayApiIpValue},
    'ca_pem_path' => {$caPathValue},
]);
\$settings->save();

echo 'configured';
PHP;

    $topology->ssh(
        'dev',
        sprintf(
            'install -d -m 0775 %s && printf %%s %s > %s',
            escapeshellarg(dirname($caPath)),
            escapeshellarg($gatewayRootCa),
            escapeshellarg($caPath),
        ),
        timeoutSeconds: 60,
    );

    $topology->ssh(
        'dev',
        'cd '.escapeshellarg($topology->checkout('dev')).' && php artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );

    $topology->ssh(
        'dev',
        sprintf(
            'sudo install -d -m 0755 %s && printf %s | sudo tee %s >/dev/null && sudo chmod 0755 %s',
            escapeshellarg(dirname($hookPath)),
            escapeshellarg("#!/usr/bin/env bash\nset -euo pipefail\necho scheduler-e2e-ran\n"),
            escapeshellarg($hookPath),
            escapeshellarg($hookPath),
        ),
        timeoutSeconds: 60,
    );
}

function scheduleSchedulerGatewayRootCa($topology): string
{
    $result = $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg('echo app(\\App\\Services\\Ca\\OrbitCaService::class)->rootCert();'),
        timeoutSeconds: 120,
    );

    return trim($result->output());
}

/**
 * @return array{run_count: int, latest_status: string|null, latest_stdout: string|null, scheduler_synced: bool}
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
    ->whereHas('node', fn (\$query) => \$query->where('name', 'app-dev-1'))
    ->first();

echo json_encode([
    'run_count' => \\App\\Models\\ScheduleRun::query()->where('schedule_key', {$scheduleKeyValue})->count(),
    'latest_status' => \$run?->status,
    'latest_stdout' => \$run?->stdout,
    'scheduler_synced' => \$state?->registry_synced_at !== null,
], JSON_THROW_ON_ERROR);
PHP;

    $result = $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );

    /** @var array{run_count: int, latest_status: string|null, latest_stdout: string|null, scheduler_synced: bool} $state */
    $state = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

    return $state;
}
