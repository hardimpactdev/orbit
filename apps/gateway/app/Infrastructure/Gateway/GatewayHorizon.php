<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Models\Node;
use App\Models\Process;

/** The Gateway Node's Horizon Process, which a release drains before its switch and starts after it. */
final readonly class GatewayHorizon
{
    public const string Name = 'horizon';

    public static function longestJobSeconds(): int
    {
        $timeouts = array_map(static fn (mixed $supervisor): int => is_array($supervisor) && is_int($supervisor['timeout'] ?? null) ? $supervisor['timeout'] : 0,
            (array) config('horizon.defaults', []));

        return max([0, ...$timeouts]);
    }

    /** How long a stop waits for running jobs: the longest job a supervisor allows, plus 30 seconds. */
    public static function drainSeconds(): int
    {
        return self::longestJobSeconds() + 30;
    }

    /**
     * The unit settings that make `systemctl stop` wait for running jobs: SIGTERM to the master only, no SIGKILL, and the drain as the stop timeout.
     *
     * @return list<string>
     */
    public static function stopDirectives(): array
    {
        return ['KillMode=mixed', 'KillSignal=SIGTERM', 'SendSIGKILL=no', 'TimeoutStopSec='.self::drainSeconds()];
    }

    public static function runs(Process $process): bool
    {
        $command = $process->runtime_config['command'] ?? null;

        return is_array($command) && array_slice($command, 1, 2) === ['artisan', 'horizon'];
    }

    public static function find(Node $gateway): ?Process
    {
        return Process::query()->whereMorphedTo('owner', $gateway)
            ->where('runtime_config->command[1]', 'artisan')
            ->where('runtime_config->command[2]', 'horizon')
            ->orderBy('id')
            ->first();
    }
}
