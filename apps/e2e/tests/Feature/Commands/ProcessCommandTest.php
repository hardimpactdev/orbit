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
    $workspace = 'feature-docs';
    $workspacePath = "{$appPath}/.worktrees/{$workspace}";
    $workspaceProcess = 'frankenphp-worker';
    $workspaceRuntimeUnit = "orbit_{$app}_{$workspace}_{$workspaceProcess}";

    try {
        e2eRestartGatewayApi($topology, 'process-command');
        processCommandSeedApp($topology, $app, $appPath);
        processCommandSeedWorkspace($topology, $app, $workspace, $workspacePath);

        $add = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:add {$process} "
            .escapeshellarg('echo worker-ready; sleep 300')
            .' --app='
            .escapeshellarg($app)
            .' --json',
            timeoutSeconds: 180,
        );
        $addPayload = processCommandPayload($add->output());

        if (($addPayload['success']['meta']['warnings'] ?? []) !== []) {
            throw new RuntimeException(processCommandDockerDiagnostics(
                $topology,
                $runtimeUnit,
                $appPath,
                $add->output().$add->errorOutput(),
            ));
        }

        expect($add->successful())
            ->toBeTrue()
            ->and($addPayload['success']['data']['process'])
            ->toMatchArray([
                'name' => $process,
                'app' => $app,
                'restart_policy' => 'never',
                'crash_notification' => 'none',
                'runtime' => 'docker',
            ])
            ->and($addPayload['success']['data']['runtime_units'][0]['name'])
            ->toBe($runtimeUnit);

        $update = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:update {$process} --app="
            .escapeshellarg($app)
            .' --restart-policy=always --json',
            timeoutSeconds: 180,
        );
        $updatePayload = processCommandPayload($update->output());

        expect($update->successful())
            ->toBeTrue()
            ->and($updatePayload['success']['data']['process']['restart_policy'])
            ->toBe('always');

        $start = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:start {$process} --app=".escapeshellarg($app).' --json',
            timeoutSeconds: 120,
            allowFailure: true,
        );
        $startPayload = processCommandPayload($start->output());

        if (! $start->successful()) {
            throw new RuntimeException(processCommandDockerDiagnostics(
                $topology,
                $runtimeUnit,
                $appPath,
                $start->output().$start->errorOutput(),
            ));
        }

        expect($start->successful())
            ->toBeTrue()
            ->and($startPayload['success']['data']['runtimes'][0])
            ->toMatchArray([
                'process' => $process,
                'app' => $app,
                'runtime_unit' => $runtimeUnit,
                'state' => 'running',
            ])
            ->and($startPayload['success']['data']['runtimes'][0]['event']['type'])
            ->toBe('started');

        sleep(1);

        $logs = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:logs {$process} --app=".escapeshellarg($app).' --lines=5 --json',
            timeoutSeconds: 120,
        );
        $logsPayload = processCommandPayload($logs->output());

        expect($logs->successful())
            ->toBeTrue()
            ->and($logsPayload['success']['data']['logs']['runtime_unit'])
            ->toBe($runtimeUnit)
            ->and(array_column($logsPayload['success']['data']['logs']['lines'], 'message'))
            ->toContain('worker-ready');

        $restart = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:restart {$process} --app=".escapeshellarg($app).' --json',
            timeoutSeconds: 120,
        );
        $restartPayload = processCommandPayload($restart->output());

        expect($restart->successful())
            ->toBeTrue()
            ->and($restartPayload['success']['data']['runtimes'][0])
            ->toMatchArray([
                'process' => $process,
                'app' => $app,
                'runtime_unit' => $runtimeUnit,
                'state' => 'running',
            ])
            ->and(array_column($restartPayload['success']['data']['runtimes'][0]['events'], 'type'))
            ->toBe(['stopped', 'started']);

        $stop = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:stop {$process} --app=".escapeshellarg($app).' --json',
            timeoutSeconds: 120,
        );
        $stopPayload = processCommandPayload($stop->output());

        expect($stop->successful())
            ->toBeTrue()
            ->and($stopPayload['success']['data']['runtimes'][0])
            ->toMatchArray([
                'process' => $process,
                'app' => $app,
                'runtime_unit' => $runtimeUnit,
                'state' => 'stopped',
            ])
            ->and($stopPayload['success']['data']['runtimes'][0]['event']['type'])
            ->toBe('stopped');

        $workspaceAdd = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:add {$workspaceProcess} "
            .escapeshellarg('echo workspace-ready; sleep 300')
            .' --app='
            .escapeshellarg($app)
            .' --workspace='
            .escapeshellarg($workspace)
            .' --json',
            timeoutSeconds: 180,
        );
        $workspaceAddPayload = processCommandPayload($workspaceAdd->output());

        expect($workspaceAdd->successful())
            ->toBeTrue()
            ->and($workspaceAddPayload['success']['data']['process'])
            ->toMatchArray([
                'name' => $workspaceProcess,
                'node' => 'app-dev-1',
                'app' => $app,
                'workspace' => $workspace,
                'runtime' => 'docker',
            ])
            ->and($workspaceAddPayload['success']['data']['runtime_units'][0]['name'])
            ->toBe($workspaceRuntimeUnit);

        $workspaceStart = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:start {$workspaceProcess} --app="
            .escapeshellarg($app)
            .' --workspace='
            .escapeshellarg($workspace)
            .' --json',
            timeoutSeconds: 120,
            allowFailure: true,
        );
        $workspaceStartPayload = processCommandPayload($workspaceStart->output());

        if (! $workspaceStart->successful()) {
            throw new RuntimeException(processCommandDockerDiagnostics(
                $topology,
                $workspaceRuntimeUnit,
                $workspacePath,
                $workspaceStart->output().$workspaceStart->errorOutput(),
            ));
        }

        expect($workspaceStart->successful())
            ->toBeTrue()
            ->and($workspaceStartPayload['success']['data']['runtimes'][0])
            ->toMatchArray([
                'process' => $workspaceProcess,
                'node' => 'app-dev-1',
                'app' => $app,
                'workspace' => $workspace,
                'runtime_unit' => $workspaceRuntimeUnit,
                'state' => 'running',
            ]);

        sleep(1);

        $workspaceLogs = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:logs {$workspaceProcess} --app="
            .escapeshellarg($app)
            .' --workspace='
            .escapeshellarg($workspace)
            .' --lines=5 --json',
            timeoutSeconds: 120,
        );
        $workspaceLogsPayload = processCommandPayload($workspaceLogs->output());

        expect($workspaceLogs->successful())
            ->toBeTrue()
            ->and($workspaceLogsPayload['success']['data']['logs'])
            ->toMatchArray([
                'process' => $workspaceProcess,
                'node' => 'app-dev-1',
                'app' => $app,
                'workspace' => $workspace,
                'runtime_unit' => $workspaceRuntimeUnit,
            ])
            ->and(array_column($workspaceLogsPayload['success']['data']['logs']['lines'], 'message'))
            ->toContain('workspace-ready');

        $workspaceStop = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:stop {$workspaceProcess} --app="
            .escapeshellarg($app)
            .' --workspace='
            .escapeshellarg($workspace)
            .' --json',
            timeoutSeconds: 120,
        );
        $workspaceStopPayload = processCommandPayload($workspaceStop->output());

        expect($workspaceStop->successful())
            ->toBeTrue()
            ->and($workspaceStopPayload['success']['data']['runtimes'][0])
            ->toMatchArray([
                'process' => $workspaceProcess,
                'node' => 'app-dev-1',
                'app' => $app,
                'workspace' => $workspace,
                'runtime_unit' => $workspaceRuntimeUnit,
                'state' => 'stopped',
            ]);

        $workspaceRemove = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:remove {$workspaceProcess} --app="
            .escapeshellarg($app)
            .' --workspace='
            .escapeshellarg($workspace)
            .' --force --json',
            timeoutSeconds: 180,
        );
        $workspaceRemovePayload = processCommandPayload($workspaceRemove->output());

        expect($workspaceRemove->successful())
            ->toBeTrue()
            ->and($workspaceRemovePayload['success']['data']['process'])
            ->toMatchArray([
                'name' => $workspaceProcess,
                'node' => 'app-dev-1',
                'app' => $app,
                'workspace' => $workspace,
            ])
            ->and($workspaceRemovePayload['success']['data']['removed_runtime_units'])
            ->toContain($workspaceRuntimeUnit);

        $remove = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:remove {$process} --app=".escapeshellarg($app).' --force --json',
            timeoutSeconds: 180,
        );
        $removePayload = processCommandPayload($remove->output());

        expect($remove->successful())
            ->toBeTrue()
            ->and($removePayload['success']['data']['process'])
            ->toMatchArray([
                'name' => $process,
                'app' => $app,
            ])
            ->and($removePayload['success']['data']['removed_runtime_units'])
            ->toContain($runtimeUnit);

        $registry = processCommandRunGatewayTinker(
            $topology,
            "\$app = \\App\\Models\\App::query()->where('name', '{$app}')->first(); echo \$app instanceof \\App\\Models\\App && \\App\\Models\\Process::query()->where('name', '{$process}')->where('owner_type', \$app->getMorphClass())->where('owner_id', \$app->id)->exists() ? 'present' : 'absent';",
        );

        expect(trim($registry->output()))->toBe('absent');
    } finally {
        processCommandCleanup($topology, $app, $appPath, $runtimeUnit, $workspacePath, $workspaceRuntimeUnit);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-operator-gateway-dev');

it('manages a node owned systemd process through process commands on an Incus app node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $runtimeUnit = 'systemd-e2e';
    $serviceName = "{$runtimeUnit}.service";
    $checkout = escapeshellarg($topology->checkout('gateway'));

    try {
        e2eRestartGatewayApi($topology, 'process-command-systemd');
        processCommandCleanupSystemdRuntime($topology, $runtimeUnit);
        processCommandRemovePreparedRedis($topology);

        $add = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:add {$runtimeUnit} "
            .escapeshellarg('printf "systemd-e2e-ready\n"; sleep 300')
            .' --node=app-dev-1 --runtime=systemd --tool=opencode --start --json',
            timeoutSeconds: 180,
            allowFailure: true,
        );
        $addPayload = processCommandPayload($add->output());

        if (! $add->successful()) {
            throw new RuntimeException(processCommandSystemdDiagnostics(
                $topology,
                $serviceName,
                $add->output().$add->errorOutput(),
            ));
        }

        expect($add->successful())
            ->toBeTrue()
            ->and($addPayload['success']['data']['process'])
            ->toMatchArray([
                'name' => $runtimeUnit,
                'node' => 'app-dev-1',
                'app' => null,
                'workspace' => null,
                'runtime' => 'systemd',
                'tool' => 'opencode',
            ])
            ->and($addPayload['success']['data']['runtime_units'][0])
            ->toMatchArray([
                'name' => $runtimeUnit,
                'context' => 'node',
            ])
            ->and($addPayload['success']['meta']['warnings'])
            ->toBe([]);

        expect(processCommandWaitForSystemdActive($topology, $serviceName))->toBe('active');

        processCommandWaitForSystemdJournal($topology, $serviceName, 'systemd-e2e-ready');

        $list = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:list --node=app-dev-1 --json",
            timeoutSeconds: 120,
        );
        $listPayload = processCommandPayload($list->output());

        expect($list->successful())
            ->toBeTrue()
            ->and($listPayload['success']['data']['context'])
            ->toBe([
                'node' => 'app-dev-1',
                'app' => null,
                'workspace' => null,
            ])
            ->and(collect($listPayload['success']['data']['processes'])->firstWhere('name', $runtimeUnit))
            ->toMatchArray([
                'node' => 'app-dev-1',
                'app' => null,
                'workspace' => null,
                'runtime' => 'systemd',
                'tool' => 'opencode',
                'runtime_unit' => $runtimeUnit,
            ]);

        $logs = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:logs {$runtimeUnit} --node=app-dev-1 --lines=20 --json",
            timeoutSeconds: 120,
        );
        $logsPayload = processCommandPayload($logs->output());

        expect($logs->successful())
            ->toBeTrue()
            ->and($logsPayload['success']['data']['logs'])
            ->toMatchArray([
                'process' => $runtimeUnit,
                'node' => 'app-dev-1',
                'app' => null,
                'workspace' => null,
                'runtime_unit' => $runtimeUnit,
            ])
            ->and(collect(array_column($logsPayload['success']['data']['logs']['lines'], 'message'))
                ->contains(fn (string $message): bool => str_contains($message, 'systemd-e2e-ready')))
            ->toBeTrue();

        $restart = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:restart {$runtimeUnit} --node=app-dev-1 --json",
            timeoutSeconds: 120,
        );
        $restartPayload = processCommandPayload($restart->output());

        expect($restart->successful())
            ->toBeTrue()
            ->and($restartPayload['success']['data']['runtimes'][0])
            ->toMatchArray([
                'process' => $runtimeUnit,
                'node' => 'app-dev-1',
                'app' => null,
                'workspace' => null,
                'runtime_unit' => $runtimeUnit,
                'state' => 'running',
            ]);

        $stop = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:stop {$runtimeUnit} --node=app-dev-1 --json",
            timeoutSeconds: 120,
        );
        $stopPayload = processCommandPayload($stop->output());

        expect($stop->successful())
            ->toBeTrue()
            ->and($stopPayload['success']['data']['runtimes'][0])
            ->toMatchArray([
                'process' => $runtimeUnit,
                'node' => 'app-dev-1',
                'app' => null,
                'workspace' => null,
                'runtime_unit' => $runtimeUnit,
                'state' => 'stopped',
            ]);

        $inactive = $topology->ssh(
            'dev',
            'systemctl is-active '.escapeshellarg($serviceName).' || true',
            timeoutSeconds: 60,
        );

        expect(trim($inactive->output()))->not->toBe('active');

        $topology->ssh(
            'dev',
            'sudo rm -f '.escapeshellarg("/etc/systemd/system/{$serviceName}").' && sudo systemctl daemon-reload',
            timeoutSeconds: 60,
        );

        $restore = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit doctor --node=app-dev-1 --family=process --key=process.runtime_unit_missing --restore --json",
            timeoutSeconds: 180,
            allowFailure: true,
        );

        if (! $restore->successful()) {
            throw new RuntimeException(processCommandSystemdDiagnostics(
                $topology,
                $serviceName,
                $restore->output().$restore->errorOutput(),
            ));
        }

        $restoreData = e2eJsonCommandData(e2eJsonCommandPayload($restore->output()));

        expect($restoreData['doctor']['healthy'])
            ->toBeTrue(json_encode($restoreData, JSON_PRETTY_PRINT))
            ->and($restoreData['doctor']['summary'])
            ->toMatchArray([
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($restoreData['doctor']['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'app-dev-1',
                'key' => 'process.runtime_unit_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ]);

        $restored = $topology->ssh(
            'dev',
            'sudo test -f '.escapeshellarg("/etc/systemd/system/{$serviceName}").' && sudo systemctl is-enabled '
                .escapeshellarg($serviceName),
            timeoutSeconds: 60,
        );

        expect(trim($restored->output()))->toBe('enabled');

        $remove = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:remove {$runtimeUnit} --node=app-dev-1 --force --json",
            timeoutSeconds: 180,
        );
        $removePayload = processCommandPayload($remove->output());

        expect($remove->successful())
            ->toBeTrue()
            ->and($removePayload['success']['data']['process'])
            ->toMatchArray([
                'name' => $runtimeUnit,
                'node' => 'app-dev-1',
                'app' => null,
                'workspace' => null,
            ])
            ->and($removePayload['success']['data']['removed_runtime_units'])
            ->toContain($runtimeUnit);
    } finally {
        processCommandCleanupSystemdRuntime($topology, $runtimeUnit);
        $topology->cleanup();
    }
})->group(
    'e2e-feature',
    'e2e-provider-incus',
    'e2e-feature-operator_gateway_app-dev',
    'e2e-feature-operator-gateway-dev',
);

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

    processCommandRunGatewayTinker($topology, $script);
}

function processCommandSeedWorkspace(E2ETopologyHarness $topology, string $app, string $workspace, string $path): void
{
    $topology->ssh('dev', 'mkdir -p '.escapeshellarg($path), timeoutSeconds: 60);

    if (e2eUsesDockerDnsAliasTopology()) {
        processCommandSeedDockerHostPath($topology, $path);
    }

    $script = <<<'PHP'
        $app = \App\Models\App::query()->where('name', '__APP__')->firstOrFail();

        \App\Models\Workspace::query()->updateOrCreate(
            ['app_id' => $app->id, 'name' => '__WORKSPACE__'],
            [
                'path' => '__PATH__',
                'lifecycle_status' => \App\Enums\WorkspaceLifecycleStatus::Expected,
            ],
        );

        echo 'seeded';
        PHP;

    $script = str_replace(
        ['__APP__', '__WORKSPACE__', '__PATH__'],
        [str_replace("'", "\\'", $app), str_replace("'", "\\'", $workspace), str_replace("'", "\\'", $path)],
        $script,
    );

    processCommandRunGatewayTinker($topology, $script);
}

function processCommandCleanup(
    E2ETopologyHarness $topology,
    string $app,
    string $path,
    string $runtimeUnit,
    string $workspacePath,
    string $workspaceRuntimeUnit,
): void {
    $checkout = escapeshellarg($topology->checkout('gateway'));

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit process:remove worker --app="
        .escapeshellarg($app)
        .' --force --json >/dev/null 2>&1 || true',
        timeoutSeconds: 180,
    );
    $topology->ssh(
        'dev',
        'docker rm -f '
            .escapeshellarg($runtimeUnit)
            .' '
            .escapeshellarg($workspaceRuntimeUnit)
            .' >/dev/null 2>&1 || true; sudo systemctl stop '
            .escapeshellarg("{$runtimeUnit}.service")
            .' >/dev/null 2>&1 || true; sudo systemctl disable '
            .escapeshellarg("{$runtimeUnit}.service")
            .' >/dev/null 2>&1 || true; sudo rm -f '
            .escapeshellarg("/etc/systemd/system/{$runtimeUnit}.service")
            .'; sudo systemctl daemon-reload >/dev/null 2>&1 || true; rm -rf '
            .escapeshellarg($workspacePath)
            .' '
            .escapeshellarg($path),
        timeoutSeconds: 120,
        allowFailure: true,
    );

    if (e2eUsesDockerDnsAliasTopology()) {
        processCommandRemoveDockerHostPath($topology, $workspacePath);
        processCommandRemoveDockerHostPath($topology, $path);
    }

    $script = "\\App\\Models\\Process::query()->whereIn('name', ['worker', 'frankenphp-worker'])->delete(); if (\$app = \\App\\Models\\App::query()->where('name', '{$app}')->first()) { \$app->delete(); }";

    processCommandRunGatewayTinker($topology, $script, allowFailure: true);
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
        PHP;

    processCommandRunGatewayTinker(
        $topology,
        str_replace('__RUNTIME_UNIT__', str_replace("'", "\\'", $runtimeUnit), $script),
        allowFailure: true,
    );
}

function processCommandRemovePreparedRedis(E2ETopologyHarness $topology): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit process:remove redis --node=app-dev-1 --force --json >/dev/null 2>&1 || true",
        timeoutSeconds: 180,
        allowFailure: true,
    );

    $topology->ssh(
        'dev',
        'docker service rm orbit-redis >/dev/null 2>&1 || true; docker rm -f orbit-redis >/dev/null 2>&1 || true',
        timeoutSeconds: 120,
        allowFailure: true,
    );

    $script = <<<'PHP'
        if ($node = \App\Models\Node::query()->where('name', 'app-dev-1')->first()) {
            $node->processes()->where('name', 'redis')->delete();
        }
        PHP;

    processCommandRunGatewayTinker($topology, $script, allowFailure: true);
}

function processCommandSystemdDiagnostics(
    E2ETopologyHarness $topology,
    string $serviceName,
    string $commandOutput,
): string {
    $service = escapeshellarg($serviceName);

    return $topology->ssh(
        'dev',
        implode(' ; ', [
            'echo "=== command ==="',
            'printf %s '.escapeshellarg($commandOutput),
            'echo',
            'echo "=== unit file ==="',
            'sudo cat '.escapeshellarg("/etc/systemd/system/{$serviceName}").' 2>&1 || true',
            'echo "=== status ==="',
            "systemctl status {$service} --no-pager -l || true",
            'echo "=== journal ==="',
            "sudo journalctl -u {$service} -n 120 --no-pager || true",
        ]),
        timeoutSeconds: 120,
        allowFailure: true,
    )->output();
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

function processCommandRunGatewayTinker(
    E2ETopologyHarness $topology,
    string $script,
    bool $allowFailure = false,
): ProcessResult {
    return e2eRunInRoleRuntime(
        $topology,
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && php apps/gateway/artisan tinker --execute='
            .escapeshellarg($script),
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

function processCommandDockerDiagnostics(
    E2ETopologyHarness $topology,
    string $runtimeUnit,
    string $path,
    string $startOutput,
): string {
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
