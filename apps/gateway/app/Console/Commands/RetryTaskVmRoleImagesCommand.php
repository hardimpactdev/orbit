<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\TaskVms\RetryTaskVmRoleImagesAction;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Console\Command;

/** Operator recovery for one owned task VM; normal task resolution still owns topology preparation. */
final class RetryTaskVmRoleImagesCommand extends Command
{
    use ReadsStringInput;

    #[\Override]
    protected $signature = 'task-vms:retry-role-images {group : Numeric task group ID} {vm : Numeric task VM ID}';

    #[\Override]
    protected $description = 'Restore the pinned recipe image transfer for an idle task group waiting for assistance.';

    public function handle(RetryTaskVmRoleImagesAction $retry): int
    {
        $group = filter_var($this->stringArgument('group'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $vm = filter_var($this->stringArgument('vm'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($group === false || $vm === false) {
            $this->error('Group and VM must be positive numeric IDs.');

            return self::FAILURE;
        }
        try {
            $retry->execute($group, $vm);
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }
        $this->info("Verified recipe image transfer for task VM [{$vm}] in group [{$group}]. Resolve assistance to retry topology preparation.");

        return self::SUCCESS;
    }
}
