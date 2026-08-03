<?php

declare(strict_types=1);

use App\Services\Tools\LegacyOpenClawRuntimeCleanup;
use Symfony\Component\Process\Process;

/**
 * Bounded PATH-stub harness for the generated OpenClaw removal-only script.
 * No real systemd/sudo mutations: stub binaries interpret a state directory.
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
 * @return array{exit: int, stdout: string, stderr: string}
 */
function openclaw_cleanup_run_script(string $root, string $script): array
{
    $env = [
        'PATH' => $root.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'ORBIT_LEGACY_OPENCLAW_HARNESS_STATE' => $root.'/state',
        'ORBIT_LEGACY_OPENCLAW_HOME' => $root.'/home/.openclaw',
        'ORBIT_LEGACY_OPENCLAW_USER' => 'agent',
        'ORBIT_LEGACY_OPENCLAW_PORT' => (string) LegacyOpenClawRuntimeCleanup::LISTEN_PORT,
        'ORBIT_LEGACY_OPENCLAW_KILL_WAIT' => '0',
    ];

    $process = new Process(['bash', '-c', $script], null, $env);
    $process->run();

    return [
        'exit' => $process->getExitCode() ?? 1,
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
    ];
}
