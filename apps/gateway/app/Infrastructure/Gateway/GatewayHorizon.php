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
