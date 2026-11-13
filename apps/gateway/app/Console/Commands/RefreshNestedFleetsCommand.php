<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Instances\NestedFleetRefresh;
use Illuminate\Console\Command;

/**
 * Admits the refresh of every nested-fleet default to a release commit (docs/cli/instance/container/fleet-refresh.mmd). A Gateway
 * release runs it from its own release with its commit. It always ends with success: the release never waits for the refresh.
 */
final class RefreshNestedFleetsCommand extends Command
{
    #[\Override]
    protected $signature = 'instances:refresh-nested-fleets {--commit= : Full SHA of the release to deploy}';

    #[\Override]
    protected $description = 'Deploy every nested-fleet default Instance to a release commit, which refreshes its inner fleet and its seed.';

    public function handle(NestedFleetRefresh $refresh): int
    {
        $commit = $this->option('commit');
        if (! is_string($commit) || preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            $this->error('The commit must be a full lowercase SHA.');

            return self::INVALID;
        }
        $results = $refresh->admitAll($commit);
        if ($results === null) {
            $this->line('The nested-fleet refresh is off (ORBIT_INSTANCES_NESTED_FLEET_REFRESH): nothing to refresh.');

            return self::SUCCESS;
        }
        if ($results === []) {
            $this->line('No nested-fleet default on a switched-on Node: nothing to refresh.');
        }
        foreach ($results as $default => $result) {
            $this->line($default.': '.$result);
        }

        return self::SUCCESS;
    }
}
