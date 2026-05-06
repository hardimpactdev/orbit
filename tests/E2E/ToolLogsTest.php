<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyFactory;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyUnavailable;

it('reads finite managed system service tool logs from an app node through the gateway', function (): void {
    try {
        $lease = E2ETopologyFactory::fromEnvironment()
            ->requireCapabilities(E2ETopologyCapabilities::vm())
            ->require(E2ETopologyKind::ControlGatewayDev);
    } catch (E2ETopologyUnavailable $exception) {
        test()->markTestSkipped($exception->getMessage());
    }

    $topology = new E2ETopologyHarness($lease)
        ->withCurrentCheckout(roles: ['control', 'gateway']);

    try {
        $config = E2EConfig::fromEnvironment();
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        E2EGatewayApi::restart(
            $topology->instance('gateway'),
            'tool-logs',
            $topology->checkout('gateway'),
            gatewayIp: $gatewayApiIp,
        );
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('control'),
            $config->controlUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );
        toolLogsUseGatewayApiUrl($topology, $gatewayApiIp);

        toolLogsSeedGatewayIntent($topology);

        $topology->ssh('dev', 'sudo systemctl restart supervisor', timeoutSeconds: 60);

        $result = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && php artisan tool:logs supervisor --node=app-dev-1 --lines=20 --json',
                escapeshellarg($topology->checkout('gateway')),
            ),
            timeoutSeconds: 180,
        );
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result->successful())->toBeTrue()
            ->and($payload['success']['data']['logs'])->toMatchArray([
                'tool' => 'supervisor',
                'node' => 'app-dev-1',
            ])
            ->and($payload['success']['data']['logs']['lines'])->not->toBeEmpty()
            ->and(implode("\n", array_column($payload['success']['data']['logs']['lines'], 'message')))->toContain('supervisor');

        $topology->ssh('dev', 'logger -t supervisor "supervisor follow local e2e"', timeoutSeconds: 30);
        $seededLocal = $topology->ssh(
            'dev',
            'sudo journalctl _SYSTEMD_UNIT=supervisor.service + SYSLOG_IDENTIFIER=supervisor -n 3 --no-pager --output=short-iso',
            timeoutSeconds: 30,
        );

        expect($seededLocal->output())->toContain('supervisor follow local e2e');

        $follow = $topology->ssh(
            'gateway',
            sprintf(
                'cd %s && timeout 20s bash -lc %s',
                escapeshellarg($topology->checkout('gateway')),
                escapeshellarg(<<<'BASH'
rm -f /tmp/orbit-tool-follow.log
timeout 8s php artisan tool:logs supervisor --node=app-dev-1 --lines=1 --follow > /tmp/orbit-tool-follow.log 2>&1 || true
test -s /tmp/orbit-tool-follow.log
grep -m 1 supervisor /tmp/orbit-tool-follow.log || { cat /tmp/orbit-tool-follow.log >&2; exit 1; }
BASH),
            ),
            timeoutSeconds: 30,
        );

        expect($follow->successful())->toBeTrue()
            ->and(trim($follow->output()))->not->toBe('');

        $topology->ssh('dev', 'logger -t supervisor "supervisor follow forwarded e2e"', timeoutSeconds: 30);

        $forwardedFollow = $topology->ssh(
            'control',
            sprintf(
                'cd %s && timeout 20s bash -lc %s',
                escapeshellarg($topology->checkout('control')),
                escapeshellarg(<<<'BASH'
rm -f /tmp/orbit-tool-follow-forwarded.log
timeout 8s php artisan tool:logs supervisor --node=app-dev-1 --lines=1 --follow > /tmp/orbit-tool-follow-forwarded.log 2>&1 || true
test -s /tmp/orbit-tool-follow-forwarded.log
grep -m 1 supervisor /tmp/orbit-tool-follow-forwarded.log || { cat /tmp/orbit-tool-follow-forwarded.log >&2; exit 1; }
BASH),
            ),
            timeoutSeconds: 30,
        );

        expect($forwardedFollow->successful())->toBeTrue()
            ->and(trim($forwardedFollow->output()))->not->toBe('');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-control-gateway-dev');

function toolLogsUseGatewayApiUrl(E2ETopologyHarness $topology, string $gatewayApiIp): void
{
    $caPath = $topology->checkout('control').'/storage/app/orbit/gateway-ca/orbit.crt';
    $gatewayUrlValue = var_export("https://{$gatewayApiIp}", true);
    $gatewayIpValue = var_export($gatewayApiIp, true);
    $caPathValue = var_export($caPath, true);

    $php = <<<PHP
\$settings = \App\Models\LocalGatewaySettings::current();
\$settings->gateway_url = {$gatewayUrlValue};
\$settings->gateway_wg_ip = {$gatewayIpValue};
\$settings->ca_pem_path = {$caPathValue};
\$settings->save();

echo 'updated';
PHP;

    $topology->ssh(
        'control',
        sprintf(
            'cd %s && php artisan tinker --execute=%s',
            escapeshellarg($topology->checkout('control')),
            escapeshellarg($php),
        ),
        timeoutSeconds: 120,
    );
}

function toolLogsSeedGatewayIntent(E2ETopologyHarness $topology): void
{
    $php = <<<'PHP'
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

\App\Models\NodeTool::query()->updateOrCreate(
    ['node_id' => $nodes->get('app-dev-1'), 'name' => 'supervisor'],
    [
        'expected_state' => 'running',
        'expected_version' => null,
        'config' => null,
        'credentials' => null,
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
