<?php

declare(strict_types=1);

namespace App\Infrastructure\SwampCredentials;

use Illuminate\Support\Facades\Process;

/**
 * When the long-running Gateway processes started: orbit-agent-view, Horizon, the engine and schedule-work. Each keeps the Swamp
 * tokens it loaded at start in memory. The command runs where they run, on the host or in the serving release container, so it
 * reads the same systemd in both modes. A process restarted by Reconcile or by a configure reload is not counted: only its main
 * process start is.
 */
class GatewayProcessStarts
{
    public const array Units = [
        'orbit-agent-view.service',
        'orbit-gateway-horizon.service',
        'orbit-gateway-schedule.service',
        'orbit-gateway-engine.service',
        'orbit-process-*-horizon.service',
        'orbit-process-*-schedule-work.service',
    ];

    /** True when every non-inactive supported unit started after `$time`, and at least one is listed. */
    public function allStartedAfter(int $time): bool
    {
        $listed = Process::timeout(30)->run([
            'systemctl', 'list-units', '--plain', '--no-legend',
            '--state=active,activating,deactivating,reloading', ...self::Units,
        ]);
        $units = array_values(array_filter(array_map(static fn (string $line): string => explode(' ', trim($line))[0],
            explode("\n", $listed->output())), static fn (string $unit): bool => str_ends_with($unit, '.service')));
        if (! $listed->successful() || $units === []) {
            return false;
        }
        foreach ($units as $unit) {
            $started = Process::timeout(30)->run(['systemctl', 'show', $unit, '--property=ExecMainStartTimestamp', '--value', '--timestamp=unix']);
            $value = ltrim(trim($started->output()), '@');
            if (! $started->successful() || ! ctype_digit($value) || (int) $value <= $time) {
                return false;
            }
        }

        return true;
    }
}
