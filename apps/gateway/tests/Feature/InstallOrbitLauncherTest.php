<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

describe('install-orbit always-cli launcher contract', function (): void {
    it('keeps the installed host command pointed at the downloaded Orbit CLI binary', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        expect($installer)
            ->toContain('ln -sf "$TARGET_DIR/bin/orbit-binary" "$LINK_PATH"')
            ->not->toContain('ln -sf "$TARGET_DIR/apps/cli/orbit" "$LINK_PATH"')
            ->not->toContain('ln -sf "$TARGET_DIR/artisan" "$LINK_PATH"');
    });

    it('writes a per-operator-host CLI config skeleton during install (D11 + D13)', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        expect($installer)
            ->toContain('write_cli_config_skeleton')
            ->toContain('.config/orbit/config.json')
            ->toContain('chmod 0700')
            ->toContain('chmod 0600')
            ->toContain('"schema_version": 1');
    });

    it('dispatches public commands through the cli artifact on gateway hosts', function (): void {
        $capture = orbitLauncherProbe(isGateway: true, arguments: ['node:list', '--json']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_REPO'])->toBe($capture['repo'])
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['ORBIT_HOST_CWD'])->toBe($capture['host_cwd'])
            ->and($capture['ORBIT_EXECUTOR_SECRET'])->toBe('')
            ->and($capture['ORBIT_OPERATION_TOKEN_SECRET'])->toBe('')
            ->and($capture['ORBIT_NODE_IDENTITY'])->toBe('')
            ->and($capture['args'])->toBe('[node:list][--json]');
    });

    it('dispatches workload-role nodes through the cli artifact with launcher environment', function (): void {
        $capture = orbitLauncherProbe(isGateway: false, arguments: ['app:list']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_REPO'])->toBe($capture['repo'])
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['ORBIT_HOST_CWD'])->toBe($capture['host_cwd'])
            ->and($capture['ORBIT_EXECUTOR_SECRET'])->toBe('')
            ->and($capture['ORBIT_OPERATION_TOKEN_SECRET'])->toBe('')
            ->and($capture['args'])->toBe('[app:list]');
    });

    it('dispatches local executor commands through the cli artifact and exports verifier secret only', function (): void {
        $capture = orbitLauncherProbe(isGateway: true, arguments: ['internal:wg-easy:state', '--json']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['ORBIT_EXECUTOR_SECRET'])->toBe('executor-secret')
            ->and($capture['ORBIT_NODE_IDENTITY'])->toBe('gateway-1')
            ->and($capture['ORBIT_OPERATION_TOKEN_SECRET'])->toBe('')
            ->and($capture['args'])->toBe('[internal:wg-easy:state][--json]');
    });

    it('preserves a wrapper supplied node identity for internal commands when the checkout env only has the verifier secret', function (): void {
        $capture = orbitLauncherProbe(
            isGateway: false,
            arguments: ['internal:database-query-local', '--json'],
            parentEnv: ['ORBIT_NODE_IDENTITY' => 'app-dev-1'],
            includeNodeIdentity: false,
        );

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['ORBIT_EXECUTOR_SECRET'])->toBe('executor-secret')
            ->and($capture['ORBIT_NODE_IDENTITY'])->toBe('app-dev-1')
            ->and($capture['ORBIT_OPERATION_TOKEN_SECRET'])->toBe('');
    });

    it('prefers a wrapper supplied node identity for internal commands over checkout env identity', function (): void {
        $capture = orbitLauncherProbe(
            isGateway: false,
            arguments: ['internal:database-query-local', '--json'],
            parentEnv: ['ORBIT_NODE_IDENTITY' => 'app-dev-1'],
        );

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['ORBIT_EXECUTOR_SECRET'])->toBe('executor-secret')
            ->and($capture['ORBIT_NODE_IDENTITY'])->toBe('app-dev-1')
            ->and($capture['ORBIT_OPERATION_TOKEN_SECRET'])->toBe('');
    });

    it('dispatches the workspace-adapter:update internal command through the cli artifact on gateway hosts', function (): void {
        $capture = orbitLauncherProbe(isGateway: true, arguments: ['internal:workspace-adapter:update', '--json']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['ORBIT_EXECUTOR_SECRET'])->toBe('executor-secret')
            ->and($capture['ORBIT_NODE_IDENTITY'])->toBe('gateway-1')
            ->and($capture['ORBIT_OPERATION_TOKEN_SECRET'])->toBe('');
    });

    it('defaults unconfigured nodes to the cli artifact', function (): void {
        $capture = orbitLauncherProbe(isGateway: null, arguments: ['node:doctor']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_REPO'])->toBe($capture['repo'])
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['ORBIT_HOST_CWD'])->toBe($capture['host_cwd'])
            ->and($capture['ORBIT_EXECUTOR_SECRET'])->toBe('')
            ->and($capture['args'])->toBe('[node:doctor]');
    });

    it('propagates launcher environment even when json and other flags are present', function (): void {
        $capture = orbitLauncherProbe(isGateway: false, arguments: ['--json', 'node:list', '--no-interaction']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_REPO'])->toBe($capture['repo'])
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['ORBIT_HOST_CWD'])->toBe($capture['host_cwd'])
            ->and($capture['args'])->toBe('[--json][node:list][--no-interaction]');
    });

    it('actively unsets inherited executor and mint secrets for public commands', function (): void {
        $capture = orbitLauncherProbe(
            isGateway: false,
            arguments: ['node:list'],
            parentEnv: [
                'ORBIT_EXECUTOR_SECRET' => 'stale-parent-secret',
                'ORBIT_OPERATION_TOKEN_SECRET' => 'stale-mint-secret',
                'ORBIT_NODE_IDENTITY' => 'stale-node',
            ],
        );

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['ORBIT_EXECUTOR_SECRET'])->toBe('')
            ->and($capture['ORBIT_OPERATION_TOKEN_SECRET'])->toBe('')
            ->and($capture['ORBIT_NODE_IDENTITY'])->toBe('');
    });

    it('documents the production repository default for installed orbit nodes', function (): void {
        $launcher = File::get(repo_path('bin/orbit'));

        expect($launcher)
            ->toContain('ORBIT_REPO')
            ->toContain('/home/orbit/orbit');
    });

    it('fails clearly when the local CLI artifact dependencies are missing', function (): void {
        $cli = File::get(repo_path('apps/cli/orbit'));

        expect($cli)
            ->toContain("__DIR__.'/vendor/autoload.php'")
            ->toContain('Orbit CLI dependencies are not installed')
            ->not->toContain("__DIR__.'/../../autoload.php'");
    });

    it('keeps the CLI artifact free of the removed bridge launcher path', function (): void {
        $launcher = File::get(repo_path('apps/cli/orbit'));

        expect($launcher)
            ->toContain("__DIR__.'/NativeCommandNormalizer.php'")
            ->not->toContain('CompatibilityBridge.php')
            ->not->toContain("dirname(__DIR__, 2).'/apps/gateway/artisan'");

        expect(File::exists(repo_path('apps/cli/CompatibilityBridge.php')))->toBeFalse();
    });

    it('keeps the launcher allow-list in sync with the CLI hidden internal commands', function (): void {
        $launcherCases = orbitLauncherInternalCases(File::get(repo_path('bin/orbit')));
        $hiddenInternalCommands = orbitCliHiddenInternalCommandSignatures();

        sort($launcherCases);
        sort($hiddenInternalCommands);

        expect($launcherCases)->toBe($hiddenInternalCommands);
    });
});

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $parentEnv
 * @return array<string, string>
 */
function orbitLauncherProbe(?bool $isGateway, array $arguments, array $parentEnv = [], bool $includeNodeIdentity = true): array
{
    $root = sys_get_temp_dir().'/orbit-launcher-contract-'.bin2hex(random_bytes(4));

    try {
        $home = "{$root}/home/orbit";
        $repo = "{$home}/orbit";
        $hostCwd = "{$root}/caller/project";
        $capturePath = "{$root}/launcher-capture";
        $fakeBin = "{$root}/bin";

        orbitLauncherPrepareFakeCheckout($repo, $fakeBin);
        File::ensureDirectoryExists($hostCwd);

        if ($isGateway !== null) {
            orbitLauncherWriteGatewayEnvironment($repo, $isGateway, includeNodeIdentity: $includeNodeIdentity);
        }

        $env = [
            'HOME' => $home,
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'ORBIT_LAUNCHER_CAPTURE' => $capturePath,
            'ORBIT_EXECUTOR_SECRET' => false,
            'ORBIT_OPERATION_TOKEN_SECRET' => false,
            'ORBIT_NODE_IDENTITY' => false,
        ];

        foreach ($parentEnv as $key => $value) {
            $env[$key] = $value;
        }

        $process = new Process(
            [$repo.'/bin/orbit', ...$arguments],
            $hostCwd,
            $env,
        );

        $process->run();

        expect($process->getExitCode())->toBe(
            0,
            $process->getErrorOutput().$process->getOutput(),
        );
        expect(File::exists($capturePath))->toBeTrue('expected the launcher to execute a fake Orbit artifact');

        return orbitLauncherReadCapture($capturePath) + [
            'repo' => $repo,
            'host_cwd' => $hostCwd,
        ];
    } finally {
        if (is_dir($root)) {
            File::deleteDirectory($root);
        }
    }
}

function orbitLauncherPrepareFakeCheckout(string $repo, string $fakeBin): void
{
    File::ensureDirectoryExists("{$repo}/bin");
    File::ensureDirectoryExists("{$repo}/apps/gateway");
    File::ensureDirectoryExists("{$repo}/apps/cli");
    File::ensureDirectoryExists($fakeBin);

    File::copy(repo_path('bin/orbit'), "{$repo}/bin/orbit");
    chmod("{$repo}/bin/orbit", 0755);

    orbitLauncherWriteExecutable("{$repo}/apps/gateway/artisan", orbitLauncherCaptureScript());
    orbitLauncherWriteExecutable("{$repo}/apps/cli/orbit", orbitLauncherCaptureScript());
    orbitLauncherWriteExecutable("{$fakeBin}/php", orbitLauncherFakePhpScript());
}

function orbitLauncherWriteGatewayEnvironment(string $repo, bool $isGateway, bool $includeNodeIdentity = true): void
{
    $value = $isGateway ? 'true' : 'false';

    $lines = [
        "ORBIT_IS_GATEWAY={$value}",
        'ORBIT_EXECUTOR_SECRET=executor-secret',
        'ORBIT_OPERATION_TOKEN_SECRET=mint-secret-must-not-leak',
    ];

    if ($includeNodeIdentity) {
        $lines[] = 'ORBIT_NODE_IDENTITY=gateway-1';
    }

    $lines[] = '';

    File::put("{$repo}/apps/gateway/.env", implode(PHP_EOL, $lines));
}

function orbitLauncherWriteExecutable(string $path, string $contents): void
{
    File::put($path, $contents);
    chmod($path, 0755);
}

function orbitLauncherCaptureScript(): string
{
    return <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
{
    printf 'target=%s\n' "$0"
    printf 'ORBIT_REPO=%s\n' "${ORBIT_REPO:-}"
    printf 'ORBIT_APP=%s\n' "${ORBIT_APP:-}"
    printf 'ORBIT_HOST_CWD=%s\n' "${ORBIT_HOST_CWD:-}"
    printf 'ORBIT_EXECUTOR_SECRET=%s\n' "${ORBIT_EXECUTOR_SECRET:-}"
    printf 'ORBIT_OPERATION_TOKEN_SECRET=%s\n' "${ORBIT_OPERATION_TOKEN_SECRET:-}"
    printf 'ORBIT_NODE_IDENTITY=%s\n' "${ORBIT_NODE_IDENTITY:-}"
    printf 'args='
    for arg in "$@"; do
        printf '[%s]' "$arg"
    done
    printf '\n'
} > "$ORBIT_LAUNCHER_CAPTURE"
BASH;
}

function orbitLauncherFakePhpScript(): string
{
    return <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
target="${1:-}"
if [ "$#" -gt 0 ]; then
    shift
fi
{
    printf 'target=%s\n' "$target"
    printf 'ORBIT_REPO=%s\n' "${ORBIT_REPO:-}"
    printf 'ORBIT_APP=%s\n' "${ORBIT_APP:-}"
    printf 'ORBIT_HOST_CWD=%s\n' "${ORBIT_HOST_CWD:-}"
    printf 'ORBIT_EXECUTOR_SECRET=%s\n' "${ORBIT_EXECUTOR_SECRET:-}"
    printf 'ORBIT_OPERATION_TOKEN_SECRET=%s\n' "${ORBIT_OPERATION_TOKEN_SECRET:-}"
    printf 'ORBIT_NODE_IDENTITY=%s\n' "${ORBIT_NODE_IDENTITY:-}"
    printf 'args='
    for arg in "$@"; do
        printf '[%s]' "$arg"
    done
    printf '\n'
} > "$ORBIT_LAUNCHER_CAPTURE"
BASH;
}

/**
 * @return array<string, string>
 */
function orbitLauncherReadCapture(string $path): array
{
    $capture = [];

    foreach (explode(PHP_EOL, trim(File::get($path))) as $line) {
        [$key, $value] = explode('=', $line, 2);
        $capture[$key] = $value;
    }

    return $capture;
}

/**
 * @return list<string>
 */
function orbitLauncherInternalCases(string $launcher): array
{
    if (! preg_match('/is_local_executor_command\(\)\s*\{\s*case\s+"\$1"\s+in\s+(.+?)\)/s', $launcher, $matches)) {
        return [];
    }

    $cases = preg_split('/\|/', $matches[1]) ?: [];

    return array_values(array_map('trim', $cases));
}

/**
 * @return list<string>
 */
function orbitCliHiddenInternalCommandSignatures(): array
{
    $config = require repo_path('apps/cli/config/commands.php');
    $signatures = [];

    foreach ($config['hidden'] ?? [] as $class) {
        if (! is_string($class) || ! str_starts_with($class, 'App\\Commands\\Internal\\')) {
            continue;
        }

        $relativePath = 'apps/cli/'.str_replace(['App\\', '\\'], ['app/', '/'], $class).'.php';
        $contents = File::get(repo_path($relativePath));

        if (! preg_match('/protected\s+\$signature\s*=\s*[\'"]([^\s\'"]+)/', $contents, $matches)) {
            continue;
        }

        $signatures[] = $matches[1];
    }

    return array_values(array_unique($signatures));
}
