<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Contracts\Process\ProcessResult;

it('routes tool lifecycle commands through a related systemd process on an app node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true)
        ->withCurrentCheckout(roles: ['operator', 'gateway']);

    try {
        toolLifecyclePrepareGatewayApi($topology);
        toolLifecycleCleanupProcessRuntime($topology);
        toolLifecycleSeedGatewayProcess($topology, withAccess: true);

        $missing = toolLifecycleRunGatewayCommand($topology, 'tool:start supervisor --node=app-dev-1 --json', allowFailure: true);
        $missingData = e2eJsonCommandData(e2eJsonCommandPayload($missing->output()));

        expect($missing->successful())->toBeFalse()
            ->and($missingData)->toMatchArray([
                'code' => 'tool.process_missing',
                'message' => "Tool 'supervisor' has no related lifecycle process on node 'app-dev-1'.",
            ]);

        $start = toolLifecycleRunGatewayCommand($topology, 'tool:start opencode-server --node=app-dev-1 --json');
        $startData = e2eJsonCommandData(e2eJsonCommandPayload($start->output()));

        expect($start->successful())->toBeTrue()
            ->and($startData['tool'])->toMatchArray([
                'name' => 'opencode-server',
                'node' => 'app-dev-1',
                'expected_state' => 'installed',
            ]);
        toolLifecycleExpectServiceState($topology, 'opencode-server.service', 'active');

        $readMessage = 'opencode-server read '.bin2hex(random_bytes(4));
        toolLifecycleEmitProcessLog($topology, $readMessage);
        toolLifecycleWaitForServiceJournal($topology, 'opencode-server.service', $readMessage);

        $logs = toolLifecycleRunGatewayCommand($topology, 'tool:logs opencode-server --node=app-dev-1 --lines=20 --json');
        $logsData = e2eJsonCommandData(e2eJsonCommandPayload($logs->output()));

        expect($logs->successful())->toBeTrue()
            ->and($logsData['logs'])->toMatchArray([
                'tool' => 'opencode-server',
                'node' => 'app-dev-1',
                'process' => 'opencode-server',
                'runtime_unit' => 'opencode-server',
            ])
            ->and($logsData['logs']['lines'])->not->toBeEmpty()
            ->and(implode("\n", array_column($logsData['logs']['lines'], 'message')))->toContain($readMessage);

        toolLifecycleAssertFollowLogs($topology);
        toolLifecyclePrepareGatewayApi($topology);

        $before = $topology->ssh('dev', 'systemctl show opencode-server.service --property=ActiveEnterTimestampMonotonic --value', timeoutSeconds: 60);
        $restart = toolLifecycleRunGatewayCommand($topology, 'tool:restart opencode-server --node=app-dev-1 --json');
        $restartData = e2eJsonCommandData(e2eJsonCommandPayload($restart->output()));
        $after = $topology->ssh('dev', 'systemctl show opencode-server.service --property=ActiveEnterTimestampMonotonic --value', timeoutSeconds: 60);

        expect($restart->successful())->toBeTrue()
            ->and($restartData['tool'])->toMatchArray([
                'name' => 'opencode-server',
                'node' => 'app-dev-1',
                'expected_state' => 'installed',
            ])
            ->and((int) trim($after->output()))->toBeGreaterThan((int) trim($before->output()));
        toolLifecycleExpectServiceState($topology, 'opencode-server.service', 'active');

        $stop = toolLifecycleRunGatewayCommand($topology, 'tool:stop opencode-server --node=app-dev-1 --json');
        $stopData = e2eJsonCommandData(e2eJsonCommandPayload($stop->output()));

        expect($stop->successful())->toBeTrue()
            ->and($stopData['tool'])->toMatchArray([
                'name' => 'opencode-server',
                'node' => 'app-dev-1',
                'expected_state' => 'installed',
            ]);
        toolLifecycleExpectServiceState($topology, 'opencode-server.service', 'inactive', allowFailure: true);
    } finally {
        toolLifecycleCleanupProcessRuntime($topology);
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

function toolLifecycleSeedGatewayProcess(E2ETopologyHarness $topology, bool $withAccess = false): void
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

    $php = <<<PHP
{$accessPhp}

\$node->update(['status' => 'active', 'platform' => 'ubuntu']);

\App\Models\NodeTool::query()->updateOrCreate(
    ['node_id' => \$node->id, 'name' => 'supervisor'],
    [
        'expected_state' => 'installed',
        'expected_version' => null,
        'config' => null,
        'credentials' => null,
    ],
);

\App\Models\NodeTool::query()->updateOrCreate(
    ['node_id' => \$node->id, 'name' => 'opencode-server'],
    [
        'expected_state' => 'installed',
        'expected_version' => null,
        'config' => null,
        'credentials' => null,
    ],
);

\$process = \$node->processes()->updateOrCreate(
    ['name' => 'opencode-server'],
    [
        'node_id' => \$node->id,
        'command' => 'touch /tmp/orbit-opencode-server-e2e.log; tail -n +1 -F /tmp/orbit-opencode-server-e2e.log',
        'restart_policy' => \App\Enums\ProcessRestartPolicy::OnFailure,
        'crash_notification' => \App\Enums\ProcessCrashNotification::None,
        'runtime' => \App\Enums\Processes\ProcessRuntime::Systemd,
        'tool' => 'opencode',
        'runtime_config' => [],
        'sort_order' => 1,
    ],
);
\$process->load('owner');

\$app = new \App\Models\App([
    'name' => \$node->name,
    'path' => \$node->orbit_path,
    'node_id' => \$node->id,
]);
\$app->setRelation('node', \$node);

\$driver = app(\App\Services\Processes\ProcessRuntimeDrivers\SystemdProcessRuntimeDriver::class);
\$runtimeUnit = \$driver->runtimeUnitName(\$app, \$process);
\$driver->remove(\$node, \$runtimeUnit);

if (! \$driver->apply(\$node, \$app, \$process)) {
    throw new \RuntimeException('Failed to apply opencode-server systemd process runtime.');
}

echo 'seeded';
PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
    );
}

function toolLifecycleRunGatewayCommand(E2ETopologyHarness $topology, string $command, bool $allowFailure = false): ProcessResult
{
    return $topology->ssh(
        'gateway',
        sprintf(
            'cd %s && orbit %s',
            escapeshellarg($topology->checkout('gateway')),
            $command,
        ),
        timeoutSeconds: 180,
        allowFailure: $allowFailure,
    );
}

function toolLifecycleExpectServiceState(E2ETopologyHarness $topology, string $serviceName, string $state, bool $allowFailure = false): void
{
    $status = $topology->ssh('dev', 'systemctl is-active '.escapeshellarg($serviceName).' || true', timeoutSeconds: 60, allowFailure: $allowFailure);

    expect(trim($status->output()))->toBe($state);
}

function toolLifecycleEmitProcessLog(E2ETopologyHarness $topology, string $message): void
{
    $topology->ssh(
        'dev',
        'printf %s '.escapeshellarg($message.PHP_EOL).' >> /tmp/orbit-opencode-server-e2e.log',
        timeoutSeconds: 30,
    );
}

function toolLifecycleWaitForServiceJournal(E2ETopologyHarness $topology, string $serviceName, string $message): void
{
    $result = $topology->ssh(
        'dev',
        sprintf(
            'timeout 20s bash -lc %s',
            escapeshellarg(sprintf(
                'until sudo journalctl -u %s -n 50 --no-pager | grep -F -m 1 %s; do sleep 0.25; done',
                escapeshellarg($serviceName),
                escapeshellarg($message),
            )),
        ),
        timeoutSeconds: 25,
    );

    expect($result->output())->toContain($message);
}

function toolLifecycleAssertFollowLogs(E2ETopologyHarness $topology): void
{
    toolLifecycleAssertFollowLogStream(
        topology: $topology,
        role: 'operator',
        checkout: $topology->checkout('operator'),
        logPath: '/tmp/orbit-tool-follow-forwarded.log',
        pidPath: '/tmp/orbit-tool-follow-forwarded.pid',
        message: 'opencode-server follow '.bin2hex(random_bytes(4)),
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
        toolLifecycleEmitProcessLog($topology, $message);
        toolLifecycleWaitForServiceJournal($topology, 'opencode-server.service', $message);
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
            'cd %s && rm -f %s %s && (nohup orbit tool:logs opencode-server --node=app-dev-1 --lines=20 --follow > %s 2>&1 < /dev/null & echo $! > %s)',
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

function toolLifecycleCleanupProcessRuntime(E2ETopologyHarness $topology): void
{
    $topology->ssh(
        'dev',
        <<<'SH'
sudo systemctl stop opencode-server.service >/dev/null 2>&1 || true
sudo systemctl disable opencode-server.service >/dev/null 2>&1 || true
sudo rm -f /etc/systemd/system/opencode-server.service
sudo systemctl daemon-reload
sudo systemctl reset-failed opencode-server.service >/dev/null 2>&1 || true
rm -f /tmp/orbit-opencode-server-e2e.log
SH,
        timeoutSeconds: 120,
        allowFailure: true,
    );

    $php = <<<'PHP'
if ($node = \App\Models\Node::query()->where('name', 'app-dev-1')->first()) {
    $node->processes()->where('name', 'opencode-server')->delete();
    $node->nodeTools()->where('name', 'opencode-server')->delete();
}

echo 'cleaned';
PHP;

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
        timeoutSeconds: 120,
        allowFailure: true,
    );
}
