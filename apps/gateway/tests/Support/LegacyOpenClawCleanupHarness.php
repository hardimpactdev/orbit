<?php

declare(strict_types=1);

use App\Services\Tools\LegacyOpenClawRuntimeCleanup;
use Symfony\Component\Process\Process;

/**
 * Bounded PATH-stub harness for the generated OpenClaw removal-only script.
 * No real systemd/sudo mutations: stub binaries interpret a state directory.
 *
 * Production cleanupScript() hard-codes security-sensitive targets. This
 * harness rewrites only the generated text in-process for tests; it never
 * introduces an env-based production override seam.
 */

function openclaw_cleanup_harness_root(): string
{
    $root = sys_get_temp_dir().'/orbit-openclaw-cleanup-'.bin2hex(random_bytes(6));
    mkdir(directory: $root.'/bin', recursive: true);
    mkdir(directory: $root.'/state', recursive: true);
    mkdir(directory: $root.'/home/.openclaw/bin', recursive: true);
    file_put_contents($root.'/home/.openclaw/bin/openclaw', "#!/bin/sh\n");
    chmod($root.'/home/.openclaw/bin/openclaw', 0o755);

    return $root;
}

/**
 * Test-only rewrite of the production cleanup script onto harness paths.
 * Production text remains immutable and never reads ORBIT_LEGACY_OPENCLAW_*.
 */
function openclaw_cleanup_script_for_harness(string $productionScript, string $root): string
{
    $home = $root.'/home/.openclaw';

    $script = str_replace(
        "OPENCLAW_HOME='/home/agent/.openclaw'",
        'OPENCLAW_HOME='.escapeshellarg($home),
        $productionScript,
    );
    $script = str_replace('KILL_WAIT=1', 'KILL_WAIT=0', $script);
    // Drop sudo on fixed home teardown so the harness can delete its temp path
    // without privileges; system-path sudo rm stubs remain no-ops.
    $script = str_replace(
        'sudo rm -rf "${OPENCLAW_HOME}"',
        'rm -rf "${OPENCLAW_HOME}"',
        $script,
    );

    return $script;
}

function openclaw_cleanup_write_stubs(string $root, bool $unkillableListener = false): void
{
    $state = $root.'/state';
    // ss-shaped line with pid= so sed extraction matches production script.
    file_put_contents($state.'/ss.out', "LISTEN 0 128 0.0.0.0:18789 0.0.0.0:* users:((\"openclaw\",pid=4242,fd=3))\n");
    file_put_contents($state.'/processes', "openclaw-bin\nopenclaw-gateway\n");
    file_put_contents($state.'/unkillable', $unkillableListener ? '1' : '0');

    $sudo = <<<'SH'
        #!/usr/bin/env bash
        set -u
        STATE_DIR="${ORBIT_LEGACY_OPENCLAW_HARNESS_STATE:?}"
        cmd="$1"
        shift || true
        case "$cmd" in
          ss)
            if [ -f "$STATE_DIR/ss.out" ]; then
              cat "$STATE_DIR/ss.out"
            fi
            exit 0
            ;;
          kill)
            # sudo kill -TERM|-KILL <pid>
            signal="$1"
            pid="$2"
            if [ "$(cat "$STATE_DIR/unkillable" 2>/dev/null || echo 0)" = "1" ]; then
              exit 0
            fi
            if [ -f "$STATE_DIR/ss.out" ]; then
              grep -v "pid=${pid}" "$STATE_DIR/ss.out" > "$STATE_DIR/ss.out.tmp" || true
              mv "$STATE_DIR/ss.out.tmp" "$STATE_DIR/ss.out"
            fi
            exit 0
            ;;
          pkill)
            # sudo pkill -u <user> -f <pattern>
            : > "$STATE_DIR/processes"
            exit 0
            ;;
          pgrep)
            if [ -s "$STATE_DIR/processes" ]; then
              exit 0
            fi
            exit 1
            ;;
          systemctl|-u|rm)
            exit 0
            ;;
          *)
            exit 0
            ;;
        esac
        SH;
    file_put_contents($root.'/bin/sudo', $sudo);
    chmod($root.'/bin/sudo', 0o755);

    // systemctl/sleep should not touch real host state when script falls through.
    file_put_contents($root.'/bin/systemctl', "#!/bin/sh\nexit 0\n");
    chmod($root.'/bin/systemctl', 0o755);
    file_put_contents($root.'/bin/sleep', "#!/bin/sh\nexit 0\n");
    chmod($root.'/bin/sleep', 0o755);
}

/**
 * @param  array<string, string>  $extraEnv
 * @return array{exit: int, stdout: string, stderr: string}
 */
function openclaw_cleanup_run_script(string $root, string $script, array $extraEnv = []): array
{
    $env = [
        'PATH' => $root.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        // Stub-only state pointer; production script must not read this family.
        'ORBIT_LEGACY_OPENCLAW_HARNESS_STATE' => $root.'/state',
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

/**
 * Production script rewritten for the harness root, ready to execute.
 */
function openclaw_cleanup_harness_script(string $root): string
{
    return openclaw_cleanup_script_for_harness(
        app(LegacyOpenClawRuntimeCleanup::class)->cleanupScript(),
        $root,
    );
}
