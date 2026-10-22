<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

/** Controls only the supervisor's wall clock; launching, waiting and killing stay real. */
final class DependencyUpdateClock
{
    /** The descendant acknowledges its final executable and open FIFO before arming the deadline. */
    public const string DelayedHook = <<<'SH'
        printf '%s\n' "$$" > hook-pid
        bash -eu -c '
            exec 3<> descendant-gate
            printf "%s\n" "$$" > child-pid
            printf start > start-marker
            IFS= read -r ignored <&3
            printf later > later-marker
        ' &
        wait "$!"
        printf later > later-marker
        SH;

    /** @return array<string, string> */
    public static function install(string $root): array
    {
        $bin = $root.'/clock-bin';
        mkdir($bin, 0o700);
        new Process(['mkfifo', $root.'/descendant-gate'])->mustRun();
        $marker = escapeshellarg($root.'/start-marker');
        // start is always 100. Neither supervisor can observe expiry until both hook PIDs are armed.
        file_put_contents($bin.'/date', <<<SH
            #!/usr/bin/env bash
            set -eu
            [ "\$#" -eq 1 ] && [ "\$1" = +%s ] || exit 9
            if [ -f {$marker} ]; then
                printf '102\n'
            else
                printf '100\n'
            fi
            SH);
        chmod($bin.'/date', 0o700);

        return ['PATH' => $bin.PATH_SEPARATOR.getenv('PATH')];
    }
}
