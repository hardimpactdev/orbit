<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Processes\RuntimeActivationRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('orbit:runtime-activation-runner
    {--operation-run-id= : Runtime activation operation run UUID to execute}')]
#[Description('Run one durable development runtime activation operation')]
class RuntimeActivationRunnerCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(RuntimeActivationRunner $runner): int
    {
        $operationRunId = $this->option('operation-run-id');

        if (! is_string($operationRunId) || trim($operationRunId) === '') {
            $this->error('The --operation-run-id option is required.');

            return self::FAILURE;
        }

        try {
            $runner->run(trim($operationRunId));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
