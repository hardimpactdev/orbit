<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Contracts\Process\ProcessResult;

it('manages a system service tool lifecycle on an app node from the gateway', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true)
        ->withCurrentCheckout(roles: ['operator', 'gateway']);

    try {
        toolLifecyclePrepareGatewayApi($topology);

        toolLifecycleSeedGatewayIntent($topology, 'stopped');
        $start = toolLifecycleRunGatewayCommand($topology, 'tool:start supervisor --node=app-dev-1 --json');
        $startPayload = toolLifecycleJson($start->output());

        expect($start->successful())->toBeTrue()
            ->and($startPayload['success']['data']['tool'])->toMatchArray([
                'name' => 'supervisor',
                'node' => 'app-dev-1',
                'expected_state' => 'running',
            ]);
        toolLifecycleExpectSupervisorState($topology, 'active');

        toolLifecycleSeedGatewayIntent($topology, 'running');
        $reload = toolLifecycleRunGatewayCommand($topology, 'tool:reload supervisor --node=app-dev-1 --json');
        $reloadPayload = toolLifecycleJson($reload->output());

        expect($reload->successful())->toBeTrue()
            ->and($reloadPayload['success']['data']['tool'])->toMatchArray([
                'name' => 'supervisor',
                'node' => 'app-dev-1',
                'expected_state' => 'running',
            ]);
        toolLifecycleExpectSupervisorState($topology, 'active');

        toolLifecycleSeedGatewayIntent($topology, 'running');
        $before = $topology->ssh('dev', 'systemctl show supervisor.service --property=ActiveEnterTimestampMonotonic --value', timeoutSeconds: 60);
        $restart = toolLifecycleRunGatewayCommand($topology, 'tool:restart supervisor --node=app-dev-1 --json');
        $restartPayload = toolLifecycleJson($restart->output());
        $after = $topology->ssh('dev', 'systemctl show supervisor.service --property=ActiveEnterTimestampMonotonic --value', timeoutSeconds: 60);

        expect($restart->successful())->toBeTrue()
            ->and($restartPayload['success']['data']['tool'])->toMatchArray([
                'name' => 'supervisor',
                'node' => 'app-dev-1',
                'expected_state' => 'running',
            ])
            ->and((int) trim($after->output()))->toBeGreaterThan((int) trim($before->output()));
        toolLifecycleExpectSupervisorState($topology, 'active');

        toolLifecycleSeedGatewayIntent($topology, 'running', withAccess: true);
        $topology->ssh('dev', 'sudo systemctl restart supervisor', timeoutSeconds: 60);

        $logs = toolLifecycleRunGatewayCommand($topology, 'tool:logs supervisor --node=app-dev-1 --lines=20 --json');
        $logsPayload = toolLifecycleJson($logs->output());

        expect($logs->successful())->toBeTrue()
            ->and($logsPayload['success']['data']['logs'])->toMatchArray([
                'tool' => 'supervisor',
                'node' => 'app-dev-1',
            ])
            ->and($logsPayload['success']['data']['logs']['lines'])->not->toBeEmpty()
            ->and(implode("\n", array_column($logsPayload['success']['data']['logs']['lines'], 'message')))->toContain('supervisor');

        toolLifecycleAssertFollowLogs($topology);

        toolLifecycleSeedGatewayIntent($topology, 'running');
        $stop = toolLifecycleRunGatewayCommand($topology, 'tool:stop supervisor --node=app-dev-1 --json');
        $stopPayload = toolLifecycleJson($stop->output());

        expect($stop->successful())->toBeTrue()
            ->and($stopPayload['success']['data']['tool'])->toMatchArray([
                'name' => 'supervisor',
                'node' => 'app-dev-1',
                'expected_state' => 'installed',
            ]);
        toolLifecycleExpectSupervisorState($topology, 'inactive', allowFailure: true);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function toolLifecyclePrepareGatewayApi(E2ETopologyHarness $topology): void
{
    $config = E2EConfig::fromEnvironment();
    $gatewayApiIp = $topology->lease()->gatewayApiIp();

    e2eRestartGatewayApi($topology, 'tool-lifecycle');
    E2EGatewayApi::waitForGatewayApi(
        $topology->instance('operator'),
        $config->operatorUser,
        $topology->lease()->sshKeyPair(),
        gatewayIp: $gatewayApiIp,
    );
    toolLifecycleUseGatewayApiUrl($topology, $gatewayApiIp);
}

function toolLifecycleUseGatewayApiUrl(E2ETopologyHarness $topology, string $gatewayApiIp): void
{
    $caPath = $topology->checkout('operator').'/apps/gateway/storage/app/orbit/gateway-ca/orbit.crt';
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
        'operator',
        sprintf(
            'cd %s && php apps/gateway/artisan tinker --execute=%s',
            escapeshellarg($topology->checkout('operator')),
            escapeshellarg($php),
        ),
        timeoutSeconds: 120,
    );
}

function toolLifecycleSeedGatewayIntent(E2ETopologyHarness $topology, string $expectedState, bool $withAccess = false): void
{
    $accessPhp = $withAccess ? <<<'PHP'
$nodes = \App\Models\Node::query()
    ->whereIn('name', ['operator-1', 'app-dev-1'])
    ->pluck('id', 'name');

foreach (['operator-1', 'app-dev-1'] as $name) {
    if (! $nodes->has($name)) {
        throw new \RuntimeException("Missing prepared node [{$name}].");
    }
}

\Illuminate\Support\Facades\DB::table('node_access')->updateOrInsert([
    'consumer_node_id' => $nodes->get('operator-1'),
    'serving_node_id' => $nodes->get('app-dev-1'),
], [
    'created_at' => now(),
    'updated_at' => now(),
]);

$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
PHP : <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
PHP;

    $stateValue = var_export($expectedState, true);
    $php = <<<PHP
{$accessPhp}

\App\Models\NodeTool::query()->updateOrCreate(
    ['node_id' => \$node->id, 'name' => 'supervisor'],
    [
        'expected_state' => {$stateValue},
        'expected_version' => null,
        'config' => null,
        'credentials' => null,
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

function toolLifecycleRunGatewayCommand(E2ETopologyHarness $topology, string $command): ProcessResult
{
    return $topology->ssh(
        'gateway',
        sprintf(
            'cd %s && php apps/gateway/artisan %s',
            escapeshellarg($topology->checkout('gateway')),
            $command,
        ),
        timeoutSeconds: 180,
    );
}

/**
 * @return array<string, mixed>
 */
function toolLifecycleJson(string $output): array
{
    return json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);
}

function toolLifecycleExpectSupervisorState(E2ETopologyHarness $topology, string $state, bool $allowFailure = false): void
{
    $status = $topology->ssh('dev', 'systemctl is-active supervisor.service || true', timeoutSeconds: 60, allowFailure: $allowFailure);

    expect(trim($status->output()))->toBe($state);
}

function toolLifecycleAssertFollowLogs(E2ETopologyHarness $topology): void
{
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
timeout 8s php apps/gateway/artisan tool:logs supervisor --node=app-dev-1 --lines=1 --follow > /tmp/orbit-tool-follow.log 2>&1 || true
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
        'operator',
        sprintf(
            'cd %s && timeout 20s bash -lc %s',
            escapeshellarg($topology->checkout('operator')),
            escapeshellarg(<<<'BASH'
rm -f /tmp/orbit-tool-follow-forwarded.log
timeout 8s php apps/gateway/artisan tool:logs supervisor --node=app-dev-1 --lines=1 --follow > /tmp/orbit-tool-follow-forwarded.log 2>&1 || true
test -s /tmp/orbit-tool-follow-forwarded.log
grep -m 1 supervisor /tmp/orbit-tool-follow-forwarded.log || { cat /tmp/orbit-tool-follow-forwarded.log >&2; exit 1; }
BASH),
        ),
        timeoutSeconds: 30,
    );

    expect($forwardedFollow->successful())->toBeTrue()
        ->and(trim($forwardedFollow->output()))->not->toBe('');
}
