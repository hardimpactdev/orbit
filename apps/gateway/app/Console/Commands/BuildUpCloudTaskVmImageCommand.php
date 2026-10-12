<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\TaskVms\UpCloudTaskVmImageBuilder;
use Illuminate\Console\Command;

/** Builds the UpCloud task VM template. Prints each stage with its time. It creates billed servers and a template. */
final class BuildUpCloudTaskVmImageCommand extends Command
{
    #[\Override]
    protected $signature = 'task-vms:build-upcloud-image';

    #[\Override]
    protected $description = 'Build the UpCloud task VM template orbit-task-base-<time> from the stock Ubuntu template, smoke-boot it, and prune older ones.';

    public function handle(UpCloudTaskVmImageBuilder $builder): int
    {
        $started = microtime(true);
        try {
            $template = $builder->build(function (string $stage, float $seconds): void {
                $this->line(sprintf('%-22s %7.1fs', $stage, $seconds));
            });
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf('UpCloud has the task VM template [%s] after %.1fs.', $template, microtime(true) - $started));

        return self::SUCCESS;
    }
}
