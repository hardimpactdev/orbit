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
    protected $signature = 'task-vms:converge {vm : Numeric TaskVM ID} {--nfs-checkout= : Checkout path to export over NFS} {--nfs-subnet= : Nested subnet that may mount it} {--nfs-interface=nested : Bridge of the nested subnet}';

    #[\Override]
    protected $description = 'Bring a running TaskVM to the pins of the release with the task VM role phases.';

    public function handle(StartTaskVmConvergeAction $converge): int
    {
        $value = $this->argument('vm');
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            $this->error('A positive TaskVM ID is required.');

            return self::FAILURE;
        }
        $checkout = $this->option('nfs-checkout');
        $subnet = $this->option('nfs-subnet');
        if (($checkout === null) !== ($subnet === null)) {
            $this->error('Name both --nfs-checkout and --nfs-subnet, or neither.');

            return self::FAILURE;
        }
        $interface = $this->option('nfs-interface');
        if ($checkout !== null && (! is_string($checkout) || preg_match('#\A/[A-Za-z0-9._/-]+\z#', $checkout) !== 1
            || ! is_string($subnet) || preg_match('#\A(?:\d{1,3}\.){3}\d{1,3}/\d{1,2}\z#', $subnet) !== 1
            || ! is_string($interface) || preg_match('/\A[a-z][a-z0-9_-]{0,14}\z/', $interface) !== 1)) {
            $this->error('The NFS checkout, subnet or interface is not valid.');

            return self::FAILURE;
        }
        try {
            $operation = $converge->execute((int) $value, $checkout === null ? null : ['checkout' => $checkout, 'subnet' => $subnet, 'interface' => $interface]);
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }
        $this->line('Operation '.$operation->id.' '.$operation->status->value);

        return self::SUCCESS;
    }
}
