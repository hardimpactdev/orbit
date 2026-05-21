<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function appAgentIdeGrantAccess(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $script = <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['control-1', 'app-dev-1'])
    ->pluck('id', 'name');

foreach (['control-1', 'app-dev-1'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\Illuminate\Support\Facades\DB::table('node_access')->updateOrInsert([
    'consumer_node_id' => $nodes->get('control-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
], [
    'created_at' => now(),
    'updated_at' => now(),
]);

echo 'granted';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

it('sets app agent IDE intent from a control caller through the gateway api', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod, withGatewayApi: true);
    $name = 'e2e-agent-'.strtolower(bin2hex(random_bytes(3)));
    $path = "/home/orbit/apps/{$name}";

    try {
        $topology->withCurrentCheckout(roles: ['control', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 'app-agent-ide');
        E2EGatewayApi::waitForGatewayApi($topology->instance('control'), $config->controlUser, $topology->lease()->sshKeyPair(), gatewayIp: $gatewayApiIp);

        appAgentIdeGrantAccess($topology);

        $topology->ssh(
            'dev',
            sprintf('mkdir -p %s', escapeshellarg("{$path}/public")),
            timeoutSeconds: 60,
        );

        $register = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan app:register %s --node=app-dev-1 --path=%s --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
                escapeshellarg($path),
            ),
            timeoutSeconds: 180,
        );

        $registrationPayload = json_decode(trim($register->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($registrationPayload['success']['data']['result']['action'])->toBe('adopted');

        $set = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan app:agent-ide %s opencode --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
            ),
            timeoutSeconds: 180,
        );

        $setPayload = json_decode(trim($set->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($setPayload['success']['data']['app']['name'])->toBe($name)
            ->and($setPayload['success']['data']['agent_ide'])->toBe([
                'adapter' => 'opencode',
                'source' => 'app',
                'effective_adapter' => 'opencode',
            ])
            ->and($setPayload['success']['data']['cleanup']['workspaces_removed'])->toBe([]);

        $clear = $topology->ssh(
            'control',
            sprintf(
                'cd %s && php artisan app:agent-ide %s inherit --json',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg($name),
            ),
            timeoutSeconds: 180,
        );

        $clearPayload = json_decode(trim($clear->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($clearPayload['success']['data']['agent_ide'])->toBe([
            'adapter' => null,
            'source' => 'default',
            'effective_adapter' => null,
        ]);

        $gatewayRecord = $topology->ssh(
            'gateway',
            'cd '.escapeshellarg($topology->checkout('gateway')).' && php artisan tinker --execute='.escapeshellarg("echo json_encode([
                'agent_ide_config' => \\App\\Models\\App::query()->where('name', '{$name}')->value('agent_ide_config'),
            ], JSON_THROW_ON_ERROR);"),
            timeoutSeconds: 120,
        );
        $state = json_decode(trim($gatewayRecord->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($state)->toMatchArray([
            'agent_ide_config' => null,
        ]);
    } finally {
        $topology->ssh('dev', 'sudo rm -rf '.escapeshellarg($path), timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator-gateway-appdev-appprod', 'e2e-feature-control-gateway-dev-prod');
