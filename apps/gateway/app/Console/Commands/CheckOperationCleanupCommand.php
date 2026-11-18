<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tasks\FileOperationCleanupTasksAction;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Operations\OperationCleanupReview;
use App\Domain\Operations\OperationVersionCleanup;
use Illuminate\Console\Command;
use Throwable;

final class CheckOperationCleanupCommand extends Command
{
    protected $signature = 'operations:check-cleanup {--fence-only : Close new starts before publication} {--release-operation= : Record cleanup candidates and file cleanup Tasks for this verified Gateway release Operation} {--json : Print the cleanup status as JSON}';

    protected $description = 'Fence deprecated Operation versions and check whether their admitted work has settled.';

    public function handle(OperationVersionCleanup $cleanup, OperationCleanupReview $reviews, FileOperationCleanupTasksAction $files): int
    {
        if ($this->option('fence-only')) {
            $fence = $cleanup->fenceOnly();
            $this->line($this->option('json') ? json_encode($fence, JSON_THROW_ON_ERROR) : 'Deprecated Operation versions fenced.');

            return self::SUCCESS;
        }
        $result = $cleanup->check();
        $operation = $this->option('release-operation');
        $failure = null;
        if ($operation !== null) {
            $layout = GatewayReleaseLayout::fromConfig();
            $release = $reviews->servingRelease($layout, (int) $operation);
            if ($release === null) {
                $this->error('This checkout does not serve the selected Gateway release.');

                return self::FAILURE;
            }
            if ($result['versions'] !== []) {
                $reviews->record($release, $layout, $result['versions']);
            }
            // The release workflow's cleanup step holds the release lock, after this release verified.
            try {
                $this->getOutput()->getErrorStyle()->writeln(sprintf('Filed %d cleanup Tasks.', $files->execute($release, $layout, $result['versions'])));
            } catch (Throwable $exception) {
                $failure = $exception;
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            foreach ($result['versions'] as $version) {
                $this->line(sprintf('%s v%d: %d outstanding', $version['kind'], $version['version'], $version['outstanding']));
            }
            $this->line($result['eligible'] ? 'Eligible for cleanup.' : 'Cleanup deferred.');
        }

        if ($failure !== null) {
            $this->error('Filing cleanup Tasks failed; the next verified release retries: '.$failure->getMessage());

            return self::FAILURE;
        }

        return $result['eligible'] ? self::SUCCESS : 75;
    }
}
