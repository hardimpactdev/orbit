<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('manages process intent runtime lifecycle and bounded logs on a prepared app node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $app = 'e2eproc'.strtolower(bin2hex(random_bytes(3)));
    $appPath = "/home/orbit/apps/{$app}";
    $process = 'worker';
    $runtimeUnit = "orbit_{$app}_main_{$process}";

    try {
        processCommandSeedApp($topology, $app, $appPath);

        $add = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit process:add {$process} ".escapeshellarg('echo worker-ready; sleep 300').' --app='.escapeshellarg($app).' --json',
            timeoutSeconds: 180,
        );
        $addPayload = processCommandPayload($add->output());

        expect($add->successful())->toBeTrue()
            ->and($addPayload['success']['data']['process'])->toMatchArray([
                'name' => $process,
                'app' => $app,
                'restart_policy' => 'never',
                'crash_notification' => 'none',
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
        );
        $startPayload = processCommandPayload($start->output());

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
            "cd {$checkout} && orbit tinker --execute=".escapeshellarg("echo \\App\\Models\\Process::query()->where('name', '{$process}')->whereHas('app', fn (\$query) => \$query->where('name', '{$app}'))->exists() ? 'present' : 'absent';"),
            timeoutSeconds: 120,
        );

        expect(trim($registry->output()))->toBe('absent');
    } finally {
        processCommandCleanup($topology, $app, $appPath, $runtimeUnit);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-control-gateway-dev');

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
        'environment' => 'development',
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
        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
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
        'sudo supervisorctl stop '.escapeshellarg($runtimeUnit).' >/dev/null 2>&1 || true; sudo rm -f '.escapeshellarg("/etc/supervisor/conf.d/{$runtimeUnit}.conf").'; sudo supervisorctl reread >/dev/null 2>&1 || true; sudo supervisorctl update >/dev/null 2>&1 || true; rm -rf '.escapeshellarg($path),
        timeoutSeconds: 120,
    );

    if (e2eUsesDockerDnsAliasTopology()) {
        processCommandRemoveDockerHostPath($topology, $path);
    }

    $script = "if (\$app = \\App\\Models\\App::query()->where('name', '{$app}')->first()) { \$app->delete(); }";

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script).' >/dev/null 2>&1 || true',
        timeoutSeconds: 120,
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
