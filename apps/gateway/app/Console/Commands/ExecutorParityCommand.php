<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Executor\ExecutorParity;
use Illuminate\Console\Command;

/**
 * The check before the signed power flags go on (ADR 0266; docs/reference/executor.md#build-parity). It fails, and names each
 * Node, while any executor Node runs another build than this Gateway pins. The switch step runs it and changes no flag on a failure.
 */
final class ExecutorParityCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:executor-parity {--json : Print the rows as JSON}';

    #[\Override]
    protected $description = 'Check that every app-dev Node runs the executor build this Gateway pins.';

    public function handle(ExecutorParity $parity): int
    {
        $rows = $parity->check();
        $differ = array_values(array_filter($rows, static fn (array $row): bool => $row['problem'] !== null));
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $differ === [], 'nodes' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Node', 'Name', 'Pinned', 'Reported', 'Service', 'Problem'], array_map(static fn (array $row): array => [
                $row['node_id'], $row['node'], substr((string) $row['expected'], 0, 16), substr((string) $row['reported'], 0, 16),
                $row['state'] ?? '', $row['problem'] ?? '',
            ], $rows));
        }
        if ($differ !== []) {
            $this->error('Executor parity fails on '.implode(', ', array_map(static fn (array $row): string => "{$row['node']} (#{$row['node_id']}): {$row['problem']}", $differ))
                .'. Keep ORBIT_EXECUTOR_WAKE_ENABLED and ORBIT_EXECUTOR_POWER_ENABLED off.');

            return self::FAILURE;
        }
        if (! $this->option('json')) {
            $this->info($rows === [] ? 'No executor Nodes.' : 'Every executor Node runs the pinned build.');
        }

        return self::SUCCESS;
    }
}
