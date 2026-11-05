<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\TaskVms\StartTaskVmSlotEnrollAction;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Console\Command;

/** Admits the enrolment of one task VM slot. There is no CLI command of its own: an operator or the host-add flow runs this. */
final class EnrollTaskVmSlotCommand extends Command
{
    use ResolvesTaskVmHostNode;

    #[\Override]
    protected $signature = 'task-vms:enroll-slot {host : Task VM host Node ID or name} {slot : Slot number on the host}';

    #[\Override]
    protected $description = 'Enrol one task VM slot (<node>-tvm-<n>) through its native workflow.';

    public function handle(StartTaskVmSlotEnrollAction $enroll): int
    {
        $host = $this->argument('host');
        $slot = $this->argument('slot');
        if (! is_string($host) || ! is_string($slot) || ! ctype_digit($slot) || (int) $slot < 1) {
            $this->error('A task VM host and a positive slot number are required.');

            return self::FAILURE;
        }
        try {
            $operation = $enroll->execute($this->activeNode($host), (int) $slot);
        } catch (ResourceOperationException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->line('Operation '.$operation->id.' '.$operation->status->value);

        return self::SUCCESS;
    }
}
