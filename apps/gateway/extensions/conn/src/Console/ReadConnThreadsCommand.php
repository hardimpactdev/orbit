<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Console;

use Illuminate\Console\Command;
use Orbit\Extensions\Conn\Domain\ConnThreadIndex;

/** One pass of the thread index's poll (docs/reference/conn.md#thread-index): every running container server, read at once. */
final class ReadConnThreadsCommand extends Command
{
    /** @var string */
    #[\Override]
    protected $signature = 'conn:threads:read {--json : Print each server\'s outcome and the pass\'s time as JSON}';

    /** @var string */
    #[\Override]
    protected $description = 'Read the threads of every running container T3 server into the Conn thread index';

    public function handle(ConnThreadIndex $index): int
    {
        if (! ConnThreadIndex::reads()) {
            $this->info('The thread index reads nothing: ORBIT_CONN_THREAD_INDEX_POLL is off.');

            return self::SUCCESS;
        }
        $started = hrtime(true);
        $results = $index->pass();
        $milliseconds = (int) round((hrtime(true) - $started) / 1e6);
        $read = array_filter($results, static fn (array $result): bool => $result['outcome'] === 'read');
        if ($this->option('json')) {
            $this->line((string) json_encode(['duration_ms' => $milliseconds, 'servers' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->info(sprintf('Read %d of %d server(s) in %d ms, %d thread(s).', count($read), count($results), $milliseconds,
            array_sum(array_column($read, 'threads'))));
        foreach ($results as $result) {
            if ($result['outcome'] === 'skipped') {
                $this->line("Skipped {$result['environment_id']}: {$result['reason']}");
            }
        }

        return self::SUCCESS;
    }
}
