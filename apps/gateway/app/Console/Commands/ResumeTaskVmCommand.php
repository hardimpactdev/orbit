<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\TaskVms\StartTaskVmResumeAction;
use Illuminate\Console\Command;

/** Admit the ordinary resume/converge lifecycle, including an already running VM. */
final class ResumeTaskVmCommand extends Command
{
    protected $signature = 'task-vms:resume {vm : Numeric TaskVM ID}';

    protected $description = 'Start or converge a TaskVM and its Node209 SSH access through its native lifecycle.';

    public function handle(StartTaskVmResumeAction $resume): int
    {
        $value = $this->argument('vm');
        if (! ctype_digit($value) || (int) $value < 1) {
            $this->error('A positive TaskVM ID is required.');

            return self::FAILURE;
        }
        $operation = $resume->execute((int) $value);
        if ($operation === null) {
            $this->error('The TaskVM is not parked or ready.');

            return self::FAILURE;
        }
        $this->line('Operation '.$operation->id.' '.$operation->status->value);

        return self::SUCCESS;
    }
}
