<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Nodes\NodeUpdateLock;

/** Real update-lock programs with private systemd stand-ins and actual process groups. */
final class NodeUpdateLockFixture
{
    public static function directory(): string
    {
        $dir = sys_get_temp_dir().'/orbit-update-lock-'.bin2hex(random_bytes(4));
        mkdir($dir.'/bin', 0755, true);
        file_put_contents($dir.'/bin/systemd-run', <<<'SH'
            #!/bin/bash
            unit=
            while [ $# -gt 0 ]; do
              case "$1" in
                --unit=*) unit=${1#--unit=}; shift ;;
                --*) shift ;;
                *) break ;;
              esac
            done
            setsid "$@" >/dev/null 2>&1 < /dev/null &
            echo $! > "$STUB_DIR/$unit.pid"
            SH);
        file_put_contents($dir.'/bin/systemctl', <<<'SH'
            #!/bin/bash
            unit=${@: -1}
            pid=$(cat "$STUB_DIR/$unit.pid" 2>/dev/null || true)
            case "$1" in
              show) if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then echo active; else echo inactive; fi ;;
              stop) [ -n "$pid" ] && kill -- "-$pid" 2>/dev/null; true ;;
            esac
            SH);
        chmod($dir.'/bin/systemd-run', 0755);
        chmod($dir.'/bin/systemctl', 0755);

        return $dir;
    }

    /** @return array{int, float} The exit code and elapsed seconds. */
    public static function acquire(string $dir, string $unit, int $wait): array
    {
        $started = microtime(true);
        $process = proc_open(
            ['bash', '-seu', '--', $unit, $dir.'/update.lock', (string) $wait, '60', $dir],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => $dir.'/bin:'.getenv('PATH'), 'STUB_DIR' => $dir],
        );
        fwrite($pipes[0], NodeUpdateLock::AcquireScript);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [proc_close($process), microtime(true) - $started];
    }

    public static function free(string $dir): bool
    {
        exec('flock -n '.escapeshellarg($dir.'/update.lock').' true', $output, $status);

        return $status === 0;
    }

    /** Waits for the wanted state, bounded at ten seconds; the caller asserts the state. */
    public static function waitUntilFree(string $dir, bool $free): void
    {
        $until = microtime(true) + 10;
        while (self::free($dir) !== $free && microtime(true) < $until) {
            usleep(10_000);
        }
    }

    public static function stop(string $dir, string $unit): void
    {
        exec('STUB_DIR='.escapeshellarg($dir).' '.escapeshellarg($dir.'/bin/systemctl').' stop '.escapeshellarg($unit));
        self::waitUntilFree($dir, free: true);
    }
}
