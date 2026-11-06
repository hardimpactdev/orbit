<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\TaskVms\StartTaskVmConvergeAction;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Console\Command;

/** Admit the task VM role phases (software, tools, check) on a running task VM, as the image build runs them on its builder. */
final class ConvergeTaskVmCommand extends Command
{
    #[\Override]
    protected $signature = 'task-vms:converge {vm : Numeric TaskVM ID} {--nested-subnet= : Subnet of the nested network the VM leads} {--nested-interface=nested : Bridge of the nested subnet}';

    #[\Override]
    protected $description = 'Bring a running TaskVM to the pins of the release with the task VM role phases.';

    public function handle(StartTaskVmConvergeAction $converge): int
    {
        $value = $this->argument('vm');
        if (! ctype_digit($value) || (int) $value < 1) {
            $this->error('A positive TaskVM ID is required.');

            return self::FAILURE;
        }
        $subnet = $this->option('nested-subnet');
        $interface = $this->option('nested-interface');
        if ($subnet !== null && (preg_match('#\A(?:\d{1,3}\.){3}\d{1,3}/\d{1,2}\z#', $subnet) !== 1
            || ! is_string($interface) || preg_match('/\A[a-z][a-z0-9_-]{0,14}\z/', $interface) !== 1)) {
            $this->error('The nested subnet or interface is not valid.');

            return self::FAILURE;
        }
        try {
            $operation = $converge->execute((int) $value, $subnet === null ? null : ['subnet' => $subnet, 'interface' => $interface]);
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }
        $this->line('Operation '.$operation->id.' '.$operation->status->value);

        return self::SUCCESS;
    }
}
