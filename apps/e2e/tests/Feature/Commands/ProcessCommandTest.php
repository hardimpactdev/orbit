<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Contracts\Process\ProcessResult;

it('manages process intent runtime lifecycle and bounded logs on a prepared app node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $app = 'e2eproc'.strtolower(bin2hex(random_bytes(3)));
    $appPath = "/home/orbit/apps/{$app}";
    $process = 'worker';
    $runtimeUnit = "orbit_{$app}_main_{$process}";

    try {
        e2eRestartGatewayApi($topology, 'process-command');
        processCommandSeedApp($topology, $app, $appPath);

        $add = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:add {$process} ".escapeshellarg('echo worker-ready; sleep 300').' --app='.escapeshellarg($app).' --json',
            timeoutSeconds: 180,
        );
        $addPayload = processCommandPayload($add->output());

        if (($addPayload['success']['meta']['warnings'] ?? []) !== []) {
            throw new RuntimeException(processCommandDockerDiagnostics($topology, $runtimeUnit, $appPath, $add->output().$add->errorOutput()));
        }

        expect($add->successful())->toBeTrue()
            ->and($addPayload['success']['data']['process'])->toMatchArray([
                'name' => $process,
                'app' => $app,
                'restart_policy' => 'never',
                'crash_notification' => 'none',
                'runtime' => 'docker',
            ])
            ->and($addPayload['success']['data']['runtime_units'][0]['name'])->toBe($runtimeUnit);

        $edit = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:edit {$process} --app=".escapeshellarg($app).' --restart-policy=always --json',
            timeoutSeconds: 180,
        );
        $editPayload = processCommandPayload($edit->output());

        expect($edit->successful())->toBeTrue()
            ->and($editPayload['success']['data']['process']['restart_policy'])->toBe('always');

        $start = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:start {$process} --app=".escapeshellarg($app).' --json',
            timeoutSeconds: 120,
            allowFailure: true,
        );
        $startPayload = processCommandPayload($start->output());

        if (! $start->successful()) {
            throw new RuntimeException(processCommandDockerDiagnostics($topology, $runtimeUnit, $appPath, $start->output().$start->errorOutput()));
        }

        expect($start->successful())->toBeTrue()
            ->and($startPayload['success']['data']['runtimes'][0])->toMatchArray([
                'process' => $process,
                'app' => $app,
                'runtime_unit' => $runtimeUnit,
                'state' => 'running',
            ])
            ->and($startPayload['success']['data']['runtimes'][0]['event']['type'])->toBe('started');

        sleep(1);

        $logs = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:logs {$process} --app=".escapeshellarg($app).' --lines=5 --json',
            timeoutSeconds: 120,
        );
        $logsPayload = processCommandPayload($logs->output());

        expect($logs->successful())->toBeTrue()
            ->and($logsPayload['success']['data']['logs']['runtime_unit'])->toBe($runtimeUnit)
            ->and(array_column($logsPayload['success']['data']['logs']['lines'], 'message'))->toContain('worker-ready');

        $restart = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:restart {$process} --app=".escapeshellarg($app).' --json',
            timeoutSeconds: 120,
        );
        $restartPayload = processCommandPayload($restart->output());

        expect($restart->successful())->toBeTrue()
            ->and($restartPayload['success']['data']['runtimes'][0])->toMatchArray([
                'process' => $process,
                'app' => $app,
                'runtime_unit' => $runtimeUnit,
                'state' => 'running',
            ])
            ->and(array_column($restartPayload['success']['data']['runtimes'][0]['events'], 'type'))->toBe(['stopped', 'started']);

        $stop = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:stop {$process} --app=".escapeshellarg($app).' --json',
            timeoutSeconds: 120,
        );
        $stopPayload = processCommandPayload($stop->output());

        expect($stop->successful())->toBeTrue()
            ->and($stopPayload['success']['data']['runtimes'][0])->toMatchArray([
                'process' => $process,
                'app' => $app,
                'runtime_unit' => $runtimeUnit,
                'state' => 'stopped',
            ])
            ->and($stopPayload['success']['data']['runtimes'][0]['event']['type'])->toBe('stopped');

        $remove = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:remove {$process} --app=".escapeshellarg($app).' --force --json',
            timeoutSeconds: 180,
        );
        $removePayload = processCommandPayload($remove->output());

        expect($remove->successful())->toBeTrue()
            ->and($removePayload['success']['data']['process'])->toMatchArray([
                'name' => $process,
                'app' => $app,
            ])
            ->and($removePayload['success']['data']['removed_runtime_units'])->toContain($runtimeUnit);

        $registry = $topology->ssh(
            'gateway',
            "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg("echo \\App\\Models\\Process::query()->where('name', '{$process}')->whereHas('app', fn (\$query) => \$query->where('name', '{$app}'))->exists() ? 'present' : 'absent';"),
            timeoutSeconds: 120,
        );

        expect(trim($registry->output()))->toBe('absent');
    } finally {
        processCommandCleanup($topology, $app, $appPath, $runtimeUnit);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

it('applies a node owned systemd process runtime unit on an Incus app node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $runtimeUnit = 'systemd-e2e';
    $serviceName = "{$runtimeUnit}.service";

    try {
        $result = processCommandApplySystemdRuntime($topology, $runtimeUnit);
        $payload = processCommandPayload($result->output());

        expect($result->successful())->toBeTrue()
            ->and($payload)->toMatchArray([
                'apply' => true,
                'start' => true,
                'runtime_unit' => $runtimeUnit,
                'service' => $serviceName,
            ]);

        expect(processCommandWaitForSystemdActive($topology, $serviceName))->toBe('active');

        processCommandWaitForSystemdJournal($topology, $serviceName, 'systemd-e2e-ready');

        $removed = processCommandRemoveSystemdRuntime($topology, $runtimeUnit);
        $removePayload = processCommandPayload($removed->output());

        expect($removed->successful())->toBeTrue()
            ->and($removePayload)->toMatchArray([
                'stop' => true,
                'remove' => true,
            ]);

        $inactive = $topology->ssh('dev', 'systemctl is-active '.escapeshellarg($serviceName).' || true', timeoutSeconds: 60);
        $unitMissing = $topology->ssh('dev', 'test ! -e '.escapeshellarg("/etc/systemd/system/{$serviceName}"), timeoutSeconds: 60);

        expect(trim($inactive->output()))->not->toBe('active')
            ->and($unitMissing->successful())->toBeTrue();
    } finally {
        processCommandCleanupSystemdRuntime($topology, $runtimeUnit);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-provider-incus', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

function processCommandSeedApp(E2ETopologyHarness $topology, string $app, string $path): void
{
    $topology->ssh('dev', 'mkdir -p '.escapeshellarg($path), timeoutSeconds: 60);

    if (e2eUsesDockerDnsAliasTopology()) {
        processCommandSeedDockerHostPath($topology, $path);
    }

    $script = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
$node->update(['status' => 'active', 'platform' => 'ubuntu']);

\App\Models\App::query()->updateOrCreate(
    ['name' => '__APP__'],
    [
        'node_id' => $node->id,
        'path' => '__PATH__',
        'document_root' => 'public',
        'php_version' => '8.5',
        'adopted' => true,
    ],
);

echo 'seeded';
PHP;

    $script = str_replace(
        ['__APP__', '__PATH__'],
        [str_replace("'", "\\'", $app), str_replace("'", "\\'", $path)],
        $script,
    );

    $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 120,
    );
}

function processCommandCleanup(E2ETopologyHarness $topology, string $app, string $path, string $runtimeUnit): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit process:remove worker --app=".escapeshellarg($app).' --force --json >/dev/null 2>&1 || true',
        timeoutSeconds: 180,
    );
    $topology->ssh(
        'dev',
        'docker rm -f '.escapeshellarg($runtimeUnit).' >/dev/null 2>&1 || true; sudo supervisorctl stop '.escapeshellarg($runtimeUnit).' >/dev/null 2>&1 || true; sudo rm -f '.escapeshellarg("/etc/supervisor/conf.d/{$runtimeUnit}.conf").'; sudo supervisorctl reread >/dev/null 2>&1 || true; sudo supervisorctl update >/dev/null 2>&1 || true; rm -rf '.escapeshellarg($path),
        timeoutSeconds: 120,
        allowFailure: true,
    );

    if (e2eUsesDockerDnsAliasTopology()) {
        processCommandRemoveDockerHostPath($topology, $path);
    }

    $script = "if (\$app = \\App\\Models\\App::query()->where('name', '{$app}')->first()) { \$app->delete(); }";

    $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute=".escapeshellarg($script).' >/dev/null 2>&1 || true',
        timeoutSeconds: 120,
    );
}

function processCommandApplySystemdRuntime(E2ETopologyHarness $topology, string $runtimeUnit): ProcessResult
{
    $script = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
$node->update(['status' => 'active', 'platform' => 'ubuntu']);

$app = \App\Models\App::query()->updateOrCreate(
    ['name' => '__APP__'],
    [
        'node_id' => $node->id,
        'path' => $node->orbit_path,
        'document_root' => 'public',
        'php_version' => '8.5',
        'adopted' => true,
    ],
);

$process = $node->processes()->updateOrCreate(
    ['name' => '__RUNTIME_UNIT__'],
    [
        'node_id' => $node->id,
        'command' => 'printf "systemd-e2e-ready\n"; sleep 300',
        'restart_policy' => \App\Enums\ProcessRestartPolicy::OnFailure,
        'crash_notification' => \App\Enums\ProcessCrashNotification::None,
        'runtime' => \App\Enums\Processes\ProcessRuntime::Systemd,
        'tool' => 'opencode',
        'runtime_config' => [],
        'sort_order' => 1,
    ],
);

$driver = app(\App\Services\Processes\ProcessRuntimeDrivers\SystemdProcessRuntimeDriver::class);
$runtimeUnit = $driver->runtimeUnitName($app, $process);

echo json_encode([
    'apply' => $driver->apply($node, $app, $process),
    'start' => $driver->start($node, $runtimeUnit),
    'runtime_unit' => $runtimeUnit,
    'service' => $runtimeUnit.'.service',
], JSON_THROW_ON_ERROR);
PHP;

    return processCommandRunGatewayTinker($topology, str_replace(
        ['__APP__', '__RUNTIME_UNIT__'],
        ['systemd-e2e-app', str_replace("'", "\\'", $runtimeUnit)],
        $script,
    ));
}

function processCommandRemoveSystemdRuntime(E2ETopologyHarness $topology, string $runtimeUnit): ProcessResult
{
    $script = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
$app = \App\Models\App::query()->where('name', 'systemd-e2e-app')->firstOrFail();
$process = $node->processes()->where('name', '__RUNTIME_UNIT__')->firstOrFail();
$driver = app(\App\Services\Processes\ProcessRuntimeDrivers\SystemdProcessRuntimeDriver::class);
$runtimeUnit = $driver->runtimeUnitName($app, $process);

echo json_encode([
    'stop' => $driver->stop($node, $runtimeUnit),
    'remove' => $driver->remove($node, $runtimeUnit),
], JSON_THROW_ON_ERROR);

$process->delete();
$app->delete();
PHP;

    return processCommandRunGatewayTinker($topology, str_replace(
        '__RUNTIME_UNIT__',
        str_replace("'", "\\'", $runtimeUnit),
        $script,
    ));
}

function processCommandCleanupSystemdRuntime(E2ETopologyHarness $topology, string $runtimeUnit): void
{
    $serviceName = "{$runtimeUnit}.service";

    $topology->ssh(
        'dev',
        sprintf(
            'sudo systemctl stop %1$s >/dev/null 2>&1 || true; sudo systemctl disable %1$s >/dev/null 2>&1 || true; sudo rm -f %2$s; sudo systemctl daemon-reload; sudo systemctl reset-failed %1$s >/dev/null 2>&1 || true',
            escapeshellarg($serviceName),
            escapeshellarg("/etc/systemd/system/{$serviceName}"),
        ),
        timeoutSeconds: 120,
        allowFailure: true,
    );

    $script = <<<'PHP'
if ($node = \App\Models\Node::query()->where('name', 'app-dev-1')->first()) {
    $node->processes()->where('name', '__RUNTIME_UNIT__')->delete();
}

if ($app = \App\Models\App::query()->where('name', 'systemd-e2e-app')->first()) {
    $app->delete();
}
PHP;

    processCommandRunGatewayTinker(
        $topology,
        str_replace('__RUNTIME_UNIT__', str_replace("'", "\\'", $runtimeUnit), $script),
        allowFailure: true,
    );
}

function processCommandWaitForSystemdActive(E2ETopologyHarness $topology, string $serviceName): string
{
    $service = escapeshellarg($serviceName);
    $result = $topology->ssh(
        'dev',
        sprintf(
            <<<'SH'
deadline=$((SECONDS+30))
while [ "$SECONDS" -lt "$deadline" ]; do
    state="$(systemctl is-active %1$s 2>&1 || true)"

    if [ "$state" = "active" ]; then
        printf 'active'
        exit 0
    fi

    sleep 0.5
done

echo '=== is-active ==='
systemctl is-active %1$s || true
echo '=== show ==='
systemctl show %1$s -p ActiveState -p SubState -p Result -p Type -p ExecMainStatus -p ExecMainCode -p NRestarts --no-pager || true
echo '=== status ==='
systemctl status %1$s --no-pager -l || true
echo '=== journal ==='
sudo journalctl -u %1$s -n 80 --no-pager || true
exit 1
SH,
            $service,
        ),
        timeoutSeconds: 45,
        allowFailure: true,
    );

    if (! $result->successful()) {
        throw new RuntimeException(trim($result->output().$result->errorOutput()));
    }

    return trim($result->output());
}

function processCommandWaitForSystemdJournal(E2ETopologyHarness $topology, string $serviceName, string $marker): void
{
    $service = escapeshellarg($serviceName);
    $marker = escapeshellarg($marker);
    $result = $topology->ssh(
        'dev',
        sprintf(
            <<<'SH'
deadline=$((SECONDS+30))
while [ "$SECONDS" -lt "$deadline" ]; do
    if sudo journalctl -u %1$s -n 50 --no-pager | grep -Fq %2$s; then
        exit 0
    fi

    sleep 0.5
done

echo '=== status ==='
systemctl status %1$s --no-pager -l || true
echo '=== journal ==='
sudo journalctl -u %1$s -n 120 --no-pager || true
exit 1
SH,
            $service,
            $marker,
        ),
        timeoutSeconds: 45,
        allowFailure: true,
    );

    if (! $result->successful()) {
        throw new RuntimeException(trim($result->output().$result->errorOutput()));
    }
}

function processCommandRunGatewayTinker(E2ETopologyHarness $topology, string $script, bool $allowFailure = false): ProcessResult
{
    return $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 180,
        allowFailure: $allowFailure,
    );
}

/**
 * @return array<string, mixed>
 */
function processCommandPayload(string $output): array
{
    return json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);
}

function processCommandSeedDockerHostPath(E2ETopologyHarness $topology, string $path): void
{
    $topology->ssh(
        'dev',
        processCommandDockerHostPathScript('mkdir -p', $path),
        timeoutSeconds: 120,
    );
}

function processCommandRemoveDockerHostPath(E2ETopologyHarness $topology, string $path): void
{
    $topology->ssh(
        'dev',
        processCommandDockerHostPathScript('rm -rf', $path),
        timeoutSeconds: 120,
        allowFailure: true,
    );
}

function processCommandDockerHostPathScript(string $operation, string $path): string
{
    $appRoot = '/home/orbit/apps/';

    if (! str_starts_with($path, $appRoot)) {
        throw new RuntimeException("Docker process E2E host-path setup only supports paths under {$appRoot}.");
    }

    $relativePath = substr($path, strlen($appRoot));

    return sprintf(
        'image="$(docker inspect -f %s "$HOSTNAME")" && docker run --rm -v /home/orbit/apps:/orbit-apps "$image" sh -lc %s',
        escapeshellarg('{{.Config.Image}}'),
        escapeshellarg($operation.' '.escapeshellarg("/orbit-apps/{$relativePath}")),
    );
}

function processCommandDockerDiagnostics(E2ETopologyHarness $topology, string $runtimeUnit, string $path, string $startOutput): string
{
    $command = implode(' ; ', [
        'echo "=== process:start ==="',
        'printf %s '.escapeshellarg($startOutput),
        'echo',
        'echo "=== docker ps ==="',
        'docker ps -a --filter name='.escapeshellarg($runtimeUnit).' 2>&1 || true',
        'echo "=== docker inspect ==="',
        'docker container inspect '.escapeshellarg($runtimeUnit).' 2>&1 || true',
        'echo "=== docker logs ==="',
        'docker logs '.escapeshellarg($runtimeUnit).' 2>&1 || true',
        'echo "=== app path ==="',
        'ls -la '.escapeshellarg($path).' 2>&1 || true',
    ]);

    return $topology->ssh('dev', $command, timeoutSeconds: 120, allowFailure: true)->output();
}
