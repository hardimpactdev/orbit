<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

const ROLE_AWARE_LAUNCHER_PENDING = 'Launcher implementation lands in T378';

describe('install-orbit role-aware launcher contract', function (): void {
    it('keeps the installed host command pointed at the checkout launcher wrapper', function (): void {
        $installer = File::get(base_path('bin/install-orbit'));

        expect($installer)
            ->toContain('ln -sf "$TARGET_DIR/bin/orbit" "$LINK_PATH"')
            ->not->toContain('ln -sf "$TARGET_DIR/apps/gateway/artisan" "$LINK_PATH"')
            ->not->toContain('ln -sf "$TARGET_DIR/apps/cli/orbit" "$LINK_PATH"')
            ->not->toContain('ln -sf "$TARGET_DIR/artisan" "$LINK_PATH"');
    })->skip(ROLE_AWARE_LAUNCHER_PENDING);

    it('dispatches gateway-role nodes through the gateway artifact with launcher environment', function (): void {
        $capture = orbitLauncherProbe(isGateway: true, arguments: ['node:list', '--json']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/gateway/artisan')
            ->and($capture['ORBIT_REPO'])->toBe($capture['repo'])
            ->and($capture['ORBIT_APP'])->toBe('gateway')
            ->and($capture['ORBIT_HOST_CWD'])->toBe($capture['host_cwd'])
            ->and($capture['args'])->toBe('[node:list][--json]');
    })->skip(ROLE_AWARE_LAUNCHER_PENDING);

    it('dispatches workload-role nodes through the cli artifact with launcher environment', function (): void {
        $capture = orbitLauncherProbe(isGateway: false, arguments: ['app:list']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_REPO'])->toBe($capture['repo'])
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['ORBIT_HOST_CWD'])->toBe($capture['host_cwd'])
            ->and($capture['args'])->toBe('[app:list]');
    })->skip(ROLE_AWARE_LAUNCHER_PENDING);

    it('defaults unconfigured nodes to the non-gateway cli artifact', function (): void {
        $capture = orbitLauncherProbe(isGateway: null, arguments: ['node:doctor']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_REPO'])->toBe($capture['repo'])
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['ORBIT_HOST_CWD'])->toBe($capture['host_cwd'])
            ->and($capture['args'])->toBe('[node:doctor]');
    })->skip(ROLE_AWARE_LAUNCHER_PENDING);

    it('propagates launcher environment even when json and other flags are present', function (): void {
        $capture = orbitLauncherProbe(isGateway: false, arguments: ['--json', 'node:list', '--no-interaction']);

        expect($capture['target'])->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_REPO'])->toBe($capture['repo'])
            ->and($capture['ORBIT_APP'])->toBe('cli')
            ->and($capture['ORBIT_HOST_CWD'])->toBe($capture['host_cwd'])
            ->and($capture['args'])->toBe('[--json][node:list][--no-interaction]');
    })->skip(ROLE_AWARE_LAUNCHER_PENDING);

    it('documents the production repository default for installed orbit nodes', function (): void {
        $launcher = File::get(base_path('bin/orbit'));

        expect($launcher)
            ->toContain('ORBIT_REPO')
            ->toContain('/home/orbit/orbit');
    })->skip(ROLE_AWARE_LAUNCHER_PENDING);
});

/**
 * @param  list<string>  $arguments
 * @return array<string, string>
 */
function orbitLauncherProbe(?bool $isGateway, array $arguments): array
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
            orbitLauncherWriteGatewayEnvironment($repo, $isGateway);
        }

        $process = new Process(
            [$repo.'/bin/orbit', ...$arguments],
            $hostCwd,
            [
                'HOME' => $home,
                'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
                'ORBIT_LAUNCHER_CAPTURE' => $capturePath,
            ],
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

    File::copy(base_path('bin/orbit'), "{$repo}/bin/orbit");
    chmod("{$repo}/bin/orbit", 0755);

    orbitLauncherWriteExecutable("{$repo}/apps/gateway/artisan", orbitLauncherCaptureScript());
    orbitLauncherWriteExecutable("{$repo}/apps/cli/orbit", orbitLauncherCaptureScript());
    orbitLauncherWriteExecutable("{$fakeBin}/php", orbitLauncherFakePhpScript());
}

function orbitLauncherWriteGatewayEnvironment(string $repo, bool $isGateway): void
{
    $value = $isGateway ? 'true' : 'false';

    File::put("{$repo}/.env", "ORBIT_IS_GATEWAY={$value}".PHP_EOL);
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
