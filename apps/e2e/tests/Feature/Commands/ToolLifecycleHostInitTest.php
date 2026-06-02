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
        $startData = e2eJsonCommandData(e2eJsonCommandPayload($start->output()));

        expect($start->successful())->toBeTrue()
            ->and($startData['tool'])->toMatchArray([
                'name' => 'supervisor',
                'node' => 'app-dev-1',
                'expected_state' => 'running',
            ]);
        toolLifecycleExpectSupervisorState($topology, 'active');

        toolLifecycleSeedGatewayIntent($topology, 'running');
        $reload = toolLifecycleRunGatewayCommand($topology, 'tool:reload supervisor --node=app-dev-1 --json');
        $reloadData = e2eJsonCommandData(e2eJsonCommandPayload($reload->output()));

        expect($reload->successful())->toBeTrue()
            ->and($reloadData['tool'])->toMatchArray([
                'name' => 'supervisor',
                'node' => 'app-dev-1',
                'expected_state' => 'running',
            ]);
        toolLifecycleExpectSupervisorState($topology, 'active');

        toolLifecycleSeedGatewayIntent($topology, 'running');
        $before = $topology->ssh('dev', 'systemctl show supervisor.service --property=ActiveEnterTimestampMonotonic --value', timeoutSeconds: 60);
        $restart = toolLifecycleRunGatewayCommand($topology, 'tool:restart supervisor --node=app-dev-1 --json');
        $restartData = e2eJsonCommandData(e2eJsonCommandPayload($restart->output()));
        $after = $topology->ssh('dev', 'systemctl show supervisor.service --property=ActiveEnterTimestampMonotonic --value', timeoutSeconds: 60);

        expect($restart->successful())->toBeTrue()
            ->and($restartData['tool'])->toMatchArray([
                'name' => 'supervisor',
                'node' => 'app-dev-1',
                'expected_state' => 'running',
            ])
            ->and((int) trim($after->output()))->toBeGreaterThan((int) trim($before->output()));
        toolLifecycleExpectSupervisorState($topology, 'active');

        toolLifecycleSeedGatewayIntent($topology, 'running', withAccess: true);
        $topology->ssh('dev', 'sudo systemctl restart supervisor', timeoutSeconds: 60);

        $logs = toolLifecycleRunGatewayCommand($topology, 'tool:logs supervisor --node=app-dev-1 --lines=20 --json');
        $logsData = e2eJsonCommandData(e2eJsonCommandPayload($logs->output()));

        expect($logs->successful())->toBeTrue()
            ->and($logsData['logs'])->toMatchArray([
                'tool' => 'supervisor',
                'node' => 'app-dev-1',
            ])
            ->and($logsData['logs']['lines'])->not->toBeEmpty()
            ->and(implode("\n", array_column($logsData['logs']['lines'], 'message')))->toContain('supervisor');

        toolLifecycleAssertFollowLogs($topology);
        toolLifecyclePrepareGatewayApi($topology);

        toolLifecycleSeedGatewayIntent($topology, 'running');
        $stop = toolLifecycleRunGatewayCommand($topology, 'tool:stop supervisor --node=app-dev-1 --json');
        $stopData = e2eJsonCommandData(e2eJsonCommandPayload($stop->output()));

        expect($stop->successful())->toBeTrue()
            ->and($stopData['tool'])->toMatchArray([
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
    $caPath = dirname($topology->checkout('operator')).'/.config/orbit/gateway-ca/orbit.crt';
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
            'cd %s && orbit %s',
            escapeshellarg($topology->checkout('gateway')),
            $command,
        ),
        timeoutSeconds: 180,
    );
}

function toolLifecycleExpectSupervisorState(E2ETopologyHarness $topology, string $state, bool $allowFailure = false): void
{
    $status = $topology->ssh('dev', 'systemctl is-active supervisor.service || true', timeoutSeconds: 60, allowFailure: $allowFailure);

    expect(trim($status->output()))->toBe($state);
}

function toolLifecycleAssertFollowLogs(E2ETopologyHarness $topology): void
{
    toolLifecycleAssertFollowLogStream(
        topology: $topology,
        role: 'operator',
        checkout: $topology->checkout('operator'),
        logPath: '/tmp/orbit-tool-follow-forwarded.log',
        pidPath: '/tmp/orbit-tool-follow-forwarded.pid',
        message: 'supervisor follow forwarded e2e',
    );
}

function toolLifecycleAssertFollowLogStream(
    E2ETopologyHarness $topology,
    string $role,
    string $checkout,
    string $logPath,
    string $pidPath,
    string $message,
): void {
    toolLifecycleStartFollowLogs($topology, $role, $checkout, $logPath, $pidPath);

    try {
        toolLifecycleStartJournalEmitter($topology, $message);
        toolLifecycleWaitForDevJournal($topology, $message);
        toolLifecycleWaitForFollowLog($topology, $role, $logPath, $message);
    } finally {
        toolLifecycleStopFollowLogs($topology, $role, $pidPath);
    }
}

function toolLifecycleStartFollowLogs(E2ETopologyHarness $topology, string $role, string $checkout, string $logPath, string $pidPath): void
{
    $topology->ssh(
        $role,
        sprintf(
            'cd %s && rm -f %s %s && (nohup orbit tool:logs supervisor --node=app-dev-1 --lines=20 --follow > %s 2>&1 < /dev/null & echo $! > %s)',
            escapeshellarg($checkout),
            escapeshellarg($logPath),
            escapeshellarg($pidPath),
            escapeshellarg($logPath),
            escapeshellarg($pidPath),
        ),
        timeoutSeconds: 30,
    );

    $topology->ssh(
        $role,
        sprintf(
            'timeout 5s bash -lc %s && sleep 1',
            escapeshellarg(sprintf(
                'until test -f %s && kill -0 "$(cat %s)" 2>/dev/null; do sleep 0.2; done',
                escapeshellarg($pidPath),
                escapeshellarg($pidPath),
            )),
        ),
        timeoutSeconds: 10,
    );
}

function toolLifecycleStartJournalEmitter(E2ETopologyHarness $topology, string $message): void
{
    $topology->ssh(
        'dev',
        sprintf(
            'nohup bash -lc %s >/tmp/orbit-tool-follow-emitter.log 2>&1 < /dev/null &',
            escapeshellarg(sprintf(
                'for i in $(seq 1 20); do logger -t supervisor %s; sleep 0.5; done',
                escapeshellarg($message),
            )),
        ),
        timeoutSeconds: 30,
    );
}

function toolLifecycleWaitForDevJournal(E2ETopologyHarness $topology, string $message): void
{
    $result = $topology->ssh(
        'dev',
        sprintf(
            'timeout 10s bash -lc %s',
            escapeshellarg(sprintf(
                'until sudo journalctl _SYSTEMD_UNIT=supervisor.service + SYSLOG_IDENTIFIER=supervisor -n 20 --no-pager --output=short-iso | grep -m 1 %s; do sleep 0.25; done',
                escapeshellarg($message),
            )),
        ),
        timeoutSeconds: 15,
    );

    expect($result->output())->toContain($message);
}

function toolLifecycleWaitForFollowLog(E2ETopologyHarness $topology, string $role, string $logPath, string $message): void
{
    $result = $topology->ssh(
        $role,
        sprintf(
            'timeout 20s bash -lc %s || { cat %s >&2 || true; exit 1; }',
            escapeshellarg(sprintf(
                'until test -s %s && grep -m 1 %s %s; do sleep 0.25; done',
                escapeshellarg($logPath),
                escapeshellarg($message),
                escapeshellarg($logPath),
            )),
            escapeshellarg($logPath),
        ),
        timeoutSeconds: 25,
        allowFailure: true,
    );
    $output = $result->output().$result->errorOutput();

    if (! $result->successful()) {
        throw new RuntimeException("Follow log did not contain [{$message}]. Output:\n{$output}");
    }

    expect($output)->toContain($message);
}

function toolLifecycleStopFollowLogs(E2ETopologyHarness $topology, string $role, string $pidPath): void
{
    $topology->ssh(
        $role,
        sprintf(
            'if test -f %s; then kill "$(cat %s)" >/dev/null 2>&1 || true; rm -f %s; fi',
            escapeshellarg($pidPath),
            escapeshellarg($pidPath),
            escapeshellarg($pidPath),
        ),
        timeoutSeconds: 10,
        allowFailure: true,
    );
}
