<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Services\Apps\RepairHappieNmbpInstance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('orbit:internal:repair-happie-nmbp-instance
    {--execute : Apply the one-off registry repair}')]
#[Description('Repair the known happie / happie-nmbp registry workaround into a canonical happie NMBP app instance')]
class RepairHappieNmbpInstanceCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(RepairHappieNmbpInstance $repair): int
    {
        try {
            if (! $this->option('execute')) {
                $this->outputResult($repair->preview());
                $this->warn('Dry run only. Re-run with --execute to apply the repair.');

                return self::SUCCESS;
            }

            $this->outputResult($repair->execute());
            $this->info('Repaired happie NMBP instance consolidation.');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *     executed: bool,
     *     actions: list<string>,
     * }  $result
     */
    private function outputResult(array $result): void
    {
        foreach ($result['actions'] as $action) {
            $this->line('- '.$action);
        }
    }
}
