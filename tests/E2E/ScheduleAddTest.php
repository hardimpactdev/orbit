<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyKind;

it('adds a schedule from the control node through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'schedule-add');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('control'),
            $config->controlUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );
        e2eGrantNodeAccess($topology);

        $appName = 'e2e-schedule-add-'.strtolower(bin2hex(random_bytes(3)));
        $scheduleName = 'e2e-sched-'.strtolower(bin2hex(random_bytes(3)));

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
                'cd %s && orbit schedule:add %s --app=%s --command=%s --interval=%s --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($scheduleName),
                escapeshellarg($appName),
                escapeshellarg('orbit schedule:run'),
                escapeshellarg('every minute'),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['schedule']['name'])->toBe($scheduleName)
            ->and($payload['success']['data']['result']['action'])->toBe('created');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-control-gateway-dev');
