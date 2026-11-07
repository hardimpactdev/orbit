<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\TaskVms\TaskVmImageRefreshTrigger;
use Illuminate\Console\Command;

/**
 * Admits the refresh of the task image of every Linux task VM host. A Gateway release runs it from its own release with the commit
 * (docs/cli/gateway/release-deploy.mmd); without one it refreshes to the release the Gateway runs, as the daily trigger does.
 */
final class RefreshTaskVmImagesCommand extends Command
{
    #[\Override]
    protected $signature = 'task-vms:refresh-images {--commit= : Full SHA of the release to refresh to}';

    #[\Override]
    protected $description = 'Refresh the task image and the topology seed of every task VM host to a release, by default the one the Gateway runs.';

    public function handle(TaskVmImageRefreshTrigger $trigger): int
    {
        $commit = $this->option('commit');
        if ($commit !== null && preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            $this->error('The commit must be a full lowercase SHA.');

            return self::INVALID;
        }
        foreach ($trigger->admitAll($commit === null ? 'operator' : 'release', $commit, attempts: 6) as $host => $result) {
            $this->line($host.': '.$result);
        }

        return self::SUCCESS;
    }
}
