<?php

declare(strict_types=1);

use App\Services\Tools\LegacyOpenCodeRuntimeCleanup;
use Symfony\Component\Process\Process;

/**
 * Bounded PATH-stub harness for the generated OpenCode removal-only script.
 * No real systemd/sudo mutations: stub binaries interpret a state directory.
 *
 * Production cleanupScript() hard-codes Orbit-managed targets
 * (/home/agent/.opencode, opencode-server.service). This harness rewrites only
 * the generated text in-process for tests; it never introduces an env-based
 * production override seam.
 */

function opencode_cleanup_harness_root(): string
{
    $root = sys_get_temp_dir().'/orbit-opencode-cleanup-'.bin2hex(random_bytes(6));
    mkdir(directory: $root.'/bin', recursive: true);
    mkdir(directory: $root.'/state', recursive: true);
    mkdir(directory: $root.'/home/.opencode/bin', recursive: true);
    mkdir(directory: $root.'/etc/systemd/system', recursive: true);
    file_put_contents($root.'/home/.opencode/bin/opencode', "#!/bin/sh\n");
    chmod($root.'/home/.opencode/bin/opencode', 0o755);
    file_put_contents($root.'/etc/systemd/system/opencode-server.service', "[Unit]\nDescription=test\n");

    return $root;
}

/**
 * Test-only rewrite of the production cleanup script onto harness paths.
 * Production text remains immutable and never reads ORBIT_LEGACY_OPENCODE_*.
 */
function opencode_cleanup_script_for_harness(string $productionScript, string $root): string
{
    $home = $root.'/home/.opencode';
    $unitDir = $root.'/etc/systemd/system';

    $script = str_replace(
        "OPENCODE_HOME='/home/agent/.opencode'",
        'OPENCODE_HOME='.escapeshellarg($home),
        $productionScript,
    );
    // Drop sudo on fixed home teardown so the harness can delete its temp path.
    $script = str_replace(
        'sudo rm -rf "${OPENCODE_HOME}"',
        'rm -rf "${OPENCODE_HOME}"',
        $script,
    );
    // Rewrite unit-file verification paths to harness-owned unit directory.
    $script = str_replace(
        '"/etc/systemd/system/opencode-server.service"',
        escapeshellarg($unitDir.'/opencode-server.service'),
        $script,
    );
    $script = str_replace(
        '"/lib/systemd/system/opencode-server.service"',
        escapeshellarg($unitDir.'/opencode-server-lib.service'),
        $script,
    );
    $script = str_replace(
        '"/usr/lib/systemd/system/opencode-server.service"',
        escapeshellarg($unitDir.'/opencode-server-usr.service'),
        $script,
    );

    // stop_unit also rm -f unit paths under /etc|/lib|/usr — rewrite those sudo rms.
    return str_replace(
        'sudo rm -f "/etc/systemd/system/${unit}" "/lib/systemd/system/${unit}" "/usr/lib/systemd/system/${unit}"',
        'rm -f '.escapeshellarg($unitDir).'/"${unit}" 2>/dev/null || true',
        $script,
    );
}

/**
 * @param  array{unkillable_process?: bool, undeletable_home?: bool, sticky_unit?: bool}  $options
 */
function opencode_cleanup_write_stubs(string $root, array $options = []): void
{
    $state = $root.'/state';
    $unkillableProcess = ($options['unkillable_process'] ?? false) === true;
    $undeletableHome = ($options['undeletable_home'] ?? false) === true;
    $stickyUnit = ($options['sticky_unit'] ?? false) === true;

    file_put_contents($state.'/processes', "opencode-bin\nopencode-serve\n");
    file_put_contents($state.'/unkillable_process', $unkillableProcess ? '1' : '0');
    file_put_contents($state.'/undeletable_home', $undeletableHome ? '1' : '0');
    file_put_contents($state.'/sticky_unit', $stickyUnit ? '1' : '0');

    $sudo = <<<'SH'
        #!/usr/bin/env bash
        set -u
        STATE_DIR="${ORBIT_LEGACY_OPENCODE_HARNESS_STATE:?}"
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
            # sticky unit: pretend rm succeeds but leave the unit file for verify
            if [ "$(cat "$STATE_DIR/sticky_unit" 2>/dev/null || echo 0)" = "1" ]; then
              exit 0
            fi
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
        STATE_DIR="${ORBIT_LEGACY_OPENCODE_HARNESS_STATE:?}"
        args=("$@")
        # Skip leading flags so we can inspect targets.
        targets=()
        for arg in "${args[@]}"; do
          case "$arg" in
            -*) ;;
            *) targets+=("$arg") ;;
          esac
        done
        if [ "$(cat "$STATE_DIR/undeletable_home" 2>/dev/null || echo 0)" = "1" ]; then
          for target in "${targets[@]}"; do
            case "$target" in
              *.opencode|*/.opencode|*/.opencode/*)
                # Fail closed: leave Orbit-managed home in place (rm || true).
                exit 0
                ;;
            esac
          done
        fi
        if [ "$(cat "$STATE_DIR/sticky_unit" 2>/dev/null || echo 0)" = "1" ]; then
          for target in "${targets[@]}"; do
            case "$target" in
              *.service)
                # Fail closed: leave unit file residue for final verification.
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
function opencode_cleanup_run_script(string $root, string $script, array $extraEnv = []): array
{
    $env = [
        'PATH' => $root.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        // Stub-only state pointer; production script must not read this family.
        'ORBIT_LEGACY_OPENCODE_HARNESS_STATE' => $root.'/state',
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

function opencode_cleanup_harness_script(string $root): string
{
    return opencode_cleanup_script_for_harness(
        app(LegacyOpenCodeRuntimeCleanup::class)->cleanupScript(),
        $root,
    );
}
