<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function installOrbitGatewayRetryTempPath(): string
{
    return sys_get_temp_dir().'/orbit-gateway-retry-'.bin2hex(random_bytes(6));
}

/**
 * Drive `start_runtime_container()` from `bin/install-orbit` with a recording
 * fake `docker`. The fake is configured to simulate the container state
 * declared in $existingState.
 *
 * @param  array{exists: bool, env: list<string>, running: bool}  $existingState
 * @return array{exit_code: int, calls: list<string>, output: string, error_output: string}
 */
function runStartRuntimeContainer(string $targetDir, string $gatewayMode, array $existingState): array
{
    $root = installOrbitGatewayRetryTempPath();
    $bin = "{$root}/bin";
    $callLog = "{$root}/docker-calls.log";
    $logFile = "{$root}/install.log";
    $stateDir = "{$root}/state";

    mkdir($bin, recursive: true);
    mkdir($stateDir, recursive: true);
    mkdir($targetDir, recursive: true);

    file_put_contents("{$stateDir}/exists", $existingState['exists'] ? '1' : '0');
    file_put_contents("{$stateDir}/running", $existingState['running'] ? 'true' : 'false');
    file_put_contents("{$stateDir}/env", implode("\n", $existingState['env']));

    $dockerScript = <<<'BASH'
#!/usr/bin/env bash
set -e

call_log="$DOCKER_CALL_LOG"
state_dir="$DOCKER_STATE_DIR"

{
    printf 'docker'
    for arg in "$@"; do
        printf ' %s' "$arg"
    done
    printf '\n'
} >> "$call_log"

case "$1" in
    info)
        exit 0
        ;;
    network)
        # network inspect orbit-network → succeed (treat the network as present)
        # network create ... → succeed
        exit 0
        ;;
    container)
        # container inspect orbit-runtime [...]
        if [ "${2:-}" = "inspect" ]; then
            if [ "$(cat "$state_dir/exists")" != "1" ]; then
                exit 1
            fi

            # --format '{{.State.Running}}'
            for arg in "$@"; do
                case "$arg" in
                    '{{.State.Running}}')
                        cat "$state_dir/running"
                        exit 0
                        ;;
                    '{{range .Config.Env}}{{println .}}{{end}}')
                        cat "$state_dir/env"
                        printf '\n'
                        exit 0
                        ;;
                esac
            done

            # Default inspect: JSON-ish stub that's good enough for plain existence checks.
            printf '{}\n'
            exit 0
        fi
        ;;
    rm)
        # docker rm -f orbit-runtime → mark non-existent + recreate
        printf '0' > "$state_dir/exists"
        exit 0
        ;;
    start)
        printf 'true' > "$state_dir/running"
        exit 0
        ;;
    run)
        # docker run -d ... → simulate container existence + propagate envs to state.
        printf '1' > "$state_dir/exists"
        printf 'true' > "$state_dir/running"

        env_lines=""
        capture_env=0

        for arg in "$@"; do
            if [ "$capture_env" = "1" ]; then
                env_lines+="${arg}"$'\n'
                capture_env=0
                continue
            fi

            if [ "$arg" = "--env" ]; then
                capture_env=1
            fi
        done

        printf '%s' "$env_lines" > "$state_dir/env"
        exit 0
        ;;
    image)
        exit 0
        ;;
esac

exit 0
BASH;

    file_put_contents("{$bin}/docker", $dockerScript);
    chmod("{$bin}/docker", 0755);

    // Stub sudo so the host-path prep step inside start_runtime_container
    // never tries to elevate on the test runner. The actual `install -d`
    // it would invoke is also no-op'd by the stub.
    file_put_contents("{$bin}/sudo", "#!/usr/bin/env bash\nshift_count=0\nfor arg in \"\$@\"; do\n    case \"\$arg\" in\n        -*) shift_count=\$((shift_count+1)) ;;\n        *) break ;;\n    esac\ndone\nshift \"\$shift_count\"\nexec \"\$@\"\n");
    chmod("{$bin}/sudo", 0755);

    file_put_contents("{$bin}/install", "#!/usr/bin/env bash\nexit 0\n");
    chmod("{$bin}/install", 0755);

    $command = sprintf(
        'set -e; export PATH=%s:$PATH; export DOCKER_CALL_LOG=%s; export DOCKER_STATE_DIR=%s; export ORBIT_INSTALL_LOG=%s; export ORBIT_INSTALL_PATH=%s; source %s; GATEWAY_MODE=%s; start_runtime_container',
        escapeshellarg($bin),
        escapeshellarg($callLog),
        escapeshellarg($stateDir),
        escapeshellarg($logFile),
        escapeshellarg($targetDir),
        escapeshellarg(repo_path('bin/install-orbit')),
        escapeshellarg($gatewayMode),
    );

    $process = new Process(['bash', '-c', $command]);
    $process->run();

    $calls = is_file($callLog)
        ? array_values(array_filter(array_map('trim', explode("\n", file_get_contents($callLog) ?: ''))))
        : [];

    return [
        'exit_code' => $process->getExitCode(),
        'calls' => $calls,
        'output' => $process->getOutput(),
        'error_output' => $process->getErrorOutput(),
        'temp_root' => $root,
    ];
}

afterEach(function (): void {
    foreach (($this->tempRoots ?? []) as $tempRoot) {
        if (is_string($tempRoot) && is_dir($tempRoot)) {
            File::deleteDirectory($tempRoot);
        }
    }
});

describe('install-orbit gateway retry', function (): void {
    it('recreates an existing non-gateway orbit-runtime container with ORBIT_IS_GATEWAY=1 when gateway mode is requested', function (): void {
        $targetDir = installOrbitGatewayRetryTempPath().'/orbit';

        $result = runStartRuntimeContainer(
            targetDir: $targetDir,
            gatewayMode: '1',
            existingState: [
                'exists' => true,
                'running' => true,
                'env' => [
                    'ORBIT_SOURCE_PATH=/opt/orbit',
                    "ORBIT_HOST_PATH={$targetDir}",
                ],
            ],
        );
        $this->tempRoots = [$result['temp_root'], dirname($targetDir)];

        expect($result['exit_code'])->toBe(0, $result['error_output']);

        $rmCall = collect($result['calls'])
            ->first(fn (string $line): bool => str_starts_with($line, 'docker rm -f orbit-runtime'));

        $runCall = collect($result['calls'])
            ->first(fn (string $line): bool => str_starts_with($line, 'docker run -d'));

        expect($rmCall)->not->toBeNull('expected docker rm -f orbit-runtime when retrying gateway mode against a non-gateway container')
            ->and($runCall)->not->toBeNull('expected docker run -d orbit-runtime to recreate the container in gateway mode')
            ->and($runCall)->toContain('--env ORBIT_IS_GATEWAY=1')
            ->and($runCall)->toContain('--env ORBIT_TRUST_WIREGUARD_PROXY_HEADER=1')
            ->and($runCall)->toContain("--env ORBIT_HOST_PATH={$targetDir}")
            ->and($runCall)->toContain('--mount type=bind,source=/etc/caddy,target=/etc/caddy')
            ->and($runCall)->toContain('--mount type=bind,source=/etc/orbit,target=/etc/orbit')
            ->and($result['error_output'])->toContain('missing ORBIT_IS_GATEWAY=1');

        $rmIndex = array_search($rmCall, $result['calls'], true);
        $runIndex = array_search($runCall, $result['calls'], true);

        expect($rmIndex)->toBeLessThan($runIndex, 'docker rm -f must run before docker run -d during gateway retry');
    });

    it('does not recreate the container when it already has ORBIT_IS_GATEWAY=1 and the matching host path', function (): void {
        $targetDir = installOrbitGatewayRetryTempPath().'/orbit';

        $result = runStartRuntimeContainer(
            targetDir: $targetDir,
            gatewayMode: '1',
            existingState: [
                'exists' => true,
                'running' => true,
                'env' => [
                    'ORBIT_SOURCE_PATH=/opt/orbit',
                    "ORBIT_HOST_PATH={$targetDir}",
                    'ORBIT_IS_GATEWAY=1',
                    'ORBIT_TRUST_WIREGUARD_PROXY_HEADER=1',
                ],
            ],
        );
        $this->tempRoots = [$result['temp_root'], dirname($targetDir)];

        expect($result['exit_code'])->toBe(0, $result['error_output']);

        $rmCall = collect($result['calls'])
            ->first(fn (string $line): bool => str_starts_with($line, 'docker rm -f orbit-runtime'));

        $runCall = collect($result['calls'])
            ->first(fn (string $line): bool => str_starts_with($line, 'docker run -d'));

        expect($rmCall)->toBeNull('an already gateway-converged container must not be recreated')
            ->and($runCall)->toBeNull('an already gateway-converged container must not be recreated');
    });

    it('starts an existing stopped gateway-converged container without recreating it', function (): void {
        $targetDir = installOrbitGatewayRetryTempPath().'/orbit';

        $result = runStartRuntimeContainer(
            targetDir: $targetDir,
            gatewayMode: '1',
            existingState: [
                'exists' => true,
                'running' => false,
                'env' => [
                    'ORBIT_SOURCE_PATH=/opt/orbit',
                    "ORBIT_HOST_PATH={$targetDir}",
                    'ORBIT_IS_GATEWAY=1',
                    'ORBIT_TRUST_WIREGUARD_PROXY_HEADER=1',
                ],
            ],
        );
        $this->tempRoots = [$result['temp_root'], dirname($targetDir)];

        expect($result['exit_code'])->toBe(0, $result['error_output']);

        $startCall = collect($result['calls'])
            ->first(fn (string $line): bool => str_starts_with($line, 'docker start orbit-runtime'));
        $rmCall = collect($result['calls'])
            ->first(fn (string $line): bool => str_starts_with($line, 'docker rm -f orbit-runtime'));

        expect($startCall)->not->toBeNull('a stopped gateway-converged container should be started, not recreated')
            ->and($rmCall)->toBeNull('a stopped gateway-converged container must not be torn down');
    });

    it('preserves the original non-gateway start-only behavior when GATEWAY_MODE is 0 and the host path matches', function (): void {
        $targetDir = installOrbitGatewayRetryTempPath().'/orbit';

        $result = runStartRuntimeContainer(
            targetDir: $targetDir,
            gatewayMode: '0',
            existingState: [
                'exists' => true,
                'running' => true,
                'env' => [
                    'ORBIT_SOURCE_PATH=/opt/orbit',
                    "ORBIT_HOST_PATH={$targetDir}",
                ],
            ],
        );
        $this->tempRoots = [$result['temp_root'], dirname($targetDir)];

        expect($result['exit_code'])->toBe(0, $result['error_output']);

        $rmCall = collect($result['calls'])
            ->first(fn (string $line): bool => str_starts_with($line, 'docker rm -f orbit-runtime'));
        $runCall = collect($result['calls'])
            ->first(fn (string $line): bool => str_starts_with($line, 'docker run -d'));

        expect($rmCall)->toBeNull('non-gateway runs must not recreate the existing container')
            ->and($runCall)->toBeNull('non-gateway runs must not start a fresh container');
    });

    it('recreates the container when ORBIT_HOST_PATH does not match the install target directory', function (): void {
        $targetDir = installOrbitGatewayRetryTempPath().'/orbit';

        $result = runStartRuntimeContainer(
            targetDir: $targetDir,
            gatewayMode: '1',
            existingState: [
                'exists' => true,
                'running' => true,
                'env' => [
                    'ORBIT_SOURCE_PATH=/opt/orbit',
                    'ORBIT_HOST_PATH=/stale/host/path',
                    'ORBIT_IS_GATEWAY=1',
                    'ORBIT_TRUST_WIREGUARD_PROXY_HEADER=1',
                ],
            ],
        );
        $this->tempRoots = [$result['temp_root'], dirname($targetDir)];

        expect($result['exit_code'])->toBe(0, $result['error_output']);

        $rmCall = collect($result['calls'])
            ->first(fn (string $line): bool => str_starts_with($line, 'docker rm -f orbit-runtime'));
        $runCall = collect($result['calls'])
            ->first(fn (string $line): bool => str_starts_with($line, 'docker run -d'));

        expect($rmCall)->not->toBeNull('stale host path requires recreate')
            ->and($runCall)->not->toBeNull('stale host path requires a fresh run')
            ->and($runCall)->toContain("--env ORBIT_HOST_PATH={$targetDir}")
            ->and($runCall)->toContain('--env ORBIT_IS_GATEWAY=1')
            ->and($runCall)->toContain('--env ORBIT_TRUST_WIREGUARD_PROXY_HEADER=1');
    });

    it('recreates an existing gateway orbit-runtime container when the proxy identity header trust env is missing', function (): void {
        $targetDir = installOrbitGatewayRetryTempPath().'/orbit';

        $result = runStartRuntimeContainer(
            targetDir: $targetDir,
            gatewayMode: '1',
            existingState: [
                'exists' => true,
                'running' => true,
                'env' => [
                    'ORBIT_SOURCE_PATH=/opt/orbit',
                    "ORBIT_HOST_PATH={$targetDir}",
                    'ORBIT_IS_GATEWAY=1',
                ],
            ],
        );
        $this->tempRoots = [$result['temp_root'], dirname($targetDir)];

        expect($result['exit_code'])->toBe(0, $result['error_output']);

        $rmCall = collect($result['calls'])
            ->first(fn (string $line): bool => str_starts_with($line, 'docker rm -f orbit-runtime'));

        $runCall = collect($result['calls'])
            ->first(fn (string $line): bool => str_starts_with($line, 'docker run -d'));

        expect($rmCall)->not->toBeNull('gateway runtime must be recreated when proxy header trust env is missing')
            ->and($runCall)->not->toBeNull('gateway runtime must be recreated when proxy header trust env is missing')
            ->and($runCall)->toContain('--env ORBIT_TRUST_WIREGUARD_PROXY_HEADER=1')
            ->and($result['error_output'])->toContain('missing ORBIT_TRUST_WIREGUARD_PROXY_HEADER=1');
    });
});
