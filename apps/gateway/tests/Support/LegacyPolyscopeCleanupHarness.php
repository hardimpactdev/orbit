<?php

declare(strict_types=1);

use App\Services\Tools\LegacyPolyscopeRuntimeCleanup;
use Symfony\Component\Process\Process;

/**
 * Bounded PATH-stub harness for the generated PolyScope removal-only script.
 * No real systemd/sudo mutations: stub binaries interpret a state directory.
 *
 * Production cleanupScript() hard-codes Orbit-managed targets
 * (/home/agent/.local/bin/polyscope-server, polyscope-server.service). This
 * harness rewrites only the generated text in-process for tests; it never
 * introduces an env-based production override seam.
 */

function polyscope_cleanup_harness_root(): string
{
    $root = sys_get_temp_dir().'/orbit-polyscope-cleanup-'.bin2hex(random_bytes(6));
    mkdir(directory: $root.'/bin', recursive: true);
    mkdir(directory: $root.'/state', recursive: true);
    mkdir(directory: $root.'/home/.local/bin', recursive: true);
    mkdir(directory: $root.'/etc/systemd/system', recursive: true);
    file_put_contents($root.'/home/.local/bin/polyscope-server', "#!/bin/sh\n");
    chmod($root.'/home/.local/bin/polyscope-server', 0o755);
    file_put_contents($root.'/etc/systemd/system/polyscope-server.service', "[Unit]\nDescription=test\n");

    return $root;
}

/**
 * Test-only rewrite of the production cleanup script onto harness paths.
 * Production text remains immutable and never reads ORBIT_LEGACY_POLYSCOPE_*.
 */
function polyscope_cleanup_script_for_harness(string $productionScript, string $root): string
{
    $bin = $root.'/home/.local/bin/polyscope-server';
    $unitDir = $root.'/etc/systemd/system';

    $script = str_replace(
        "POLYSCOPE_BIN='/home/agent/.local/bin/polyscope-server'",
        'POLYSCOPE_BIN='.escapeshellarg($bin),
        $productionScript,
    );
    // Drop sudo on fixed binary teardown so the harness can delete its temp path.
    $script = str_replace(
        'sudo rm -f "${POLYSCOPE_BIN}"',
        'rm -f "${POLYSCOPE_BIN}"',
        $script,
    );
    $script = str_replace(
        '"/etc/systemd/system/polyscope-server.service"',
        escapeshellarg($unitDir.'/polyscope-server.service'),
        $script,
    );
    $script = str_replace(
        '"/lib/systemd/system/polyscope-server.service"',
        escapeshellarg($unitDir.'/polyscope-server-lib.service'),
        $script,
    );
    $script = str_replace(
        '"/usr/lib/systemd/system/polyscope-server.service"',
        escapeshellarg($unitDir.'/polyscope-server-usr.service'),
        $script,
    );
    $script = str_replace(
        'sudo rm -f "/etc/systemd/system/${unit}" "/lib/systemd/system/${unit}" "/usr/lib/systemd/system/${unit}"',
        'rm -f '.escapeshellarg($unitDir).'/"${unit}" 2>/dev/null || true',
        $script,
    );

    return $script;
}

/**
 * @param  array{unkillable_process?: bool, sticky_binary?: bool, sticky_unit?: bool}  $options
 */
function polyscope_cleanup_write_stubs(string $root, array $options = []): void
{
    $state = $root.'/state';
    $unkillableProcess = ($options['unkillable_process'] ?? false) === true;
    $stickyBinary = ($options['sticky_binary'] ?? false) === true;
    $stickyUnit = ($options['sticky_unit'] ?? false) === true;

    file_put_contents($state.'/processes', "polyscope-server\n");
    file_put_contents($state.'/unkillable_process', $unkillableProcess ? '1' : '0');
    file_put_contents($state.'/sticky_binary', $stickyBinary ? '1' : '0');
    file_put_contents($state.'/sticky_unit', $stickyUnit ? '1' : '0');

    $sudo = <<<'SH'
        #!/usr/bin/env bash
        set -u
        STATE_DIR="${ORBIT_LEGACY_POLYSCOPE_HARNESS_STATE:?}"
        cmd="$1"
        shift || true
        case "$cmd" in
          pkill)
            if [ "$(cat "$STATE_DIR/unkillable_process" 2>/dev/null || echo 0)" = "1" ]; then
              exit 0
            fi
            : > "$STATE_DIR/processes"
            exit 0
            ;;
          pgrep)
            if [ -s "$STATE_DIR/processes" ]; then
              exit 0
            fi
            exit 1
            ;;
          systemctl|-u)
            exit 0
            ;;
          rm)
            exit 0
            ;;
          *)
            exit 0
            ;;
        esac
        SH;
    file_put_contents($root.'/bin/sudo', $sudo);
    chmod($root.'/bin/sudo', 0o755);

    $rm = <<<'SH'
        #!/usr/bin/env bash
        set -u
        STATE_DIR="${ORBIT_LEGACY_POLYSCOPE_HARNESS_STATE:?}"
        args=("$@")
        targets=()
        for arg in "${args[@]}"; do
          case "$arg" in
            -*) ;;
            *) targets+=("$arg") ;;
          esac
        done
        if [ "$(cat "$STATE_DIR/sticky_binary" 2>/dev/null || echo 0)" = "1" ]; then
          for target in "${targets[@]}"; do
            case "$target" in
              *polyscope-server)
                # Fail closed: leave Orbit-managed binary (rm || true).
                exit 0
                ;;
            esac
          done
        fi
        if [ "$(cat "$STATE_DIR/sticky_unit" 2>/dev/null || echo 0)" = "1" ]; then
          for target in "${targets[@]}"; do
            case "$target" in
              *.service)
                exit 0
                ;;
            esac
          done
        fi
        exec /bin/rm "$@"
        SH;
    file_put_contents($root.'/bin/rm', $rm);
    chmod($root.'/bin/rm', 0o755);

    file_put_contents($root.'/bin/systemctl', "#!/bin/sh\nexit 0\n");
    chmod($root.'/bin/systemctl', 0o755);
}

/**
 * @param  array<string, string>  $extraEnv
 * @return array{exit: int, stdout: string, stderr: string}
 */
function polyscope_cleanup_run_script(string $root, string $script, array $extraEnv = []): array
{
    $env = [
        'PATH' => $root.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        // Stub-only state pointer; production script must not read this family.
        'ORBIT_LEGACY_POLYSCOPE_HARNESS_STATE' => $root.'/state',
        ...$extraEnv,
    ];

    $process = new Process(['bash', '-c', $script], null, $env);
    $process->run();

    return [
        'exit' => $process->getExitCode() ?? 1,
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
    ];
}

function polyscope_cleanup_harness_script(string $root): string
{
    return polyscope_cleanup_script_for_harness(
        app(LegacyPolyscopeRuntimeCleanup::class)->cleanupScript(),
        $root,
    );
}
