<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tasks\TaskWorkspaceLeaseRecovery;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Models\Node;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Console\Command;
use Throwable;

final class ReleaseTaskWorkspaceLeaseCommand extends Command
{
    use ReadsStringInput;

    #[\Override]
    protected $signature = 'tasks:lease:release {lease-id : Exact held workspace lease ID} {--reason= : Required investigation reason} {--gateway-node= : Node serving the recorded foreign Gateway hostname}';

    #[\Override]
    protected $description = 'Seal late starts and release only workspace ownership whose termination can be proven';

    public function handle(TaskWorkspaceLeaseRecovery $recovery): int
    {
        $id = $this->stringArgument('lease-id');
        $reason = $this->stringOption('reason') ?? '';
        $gatewayId = $this->stringOption('gateway-node') ?? '';
        $lease = ctype_digit($id) ? TaskWorkspaceLeaseRecord::query()->find($id) : null;
        $gateway = $gatewayId !== '' && ctype_digit($gatewayId) ? Node::query()->find($gatewayId) : null;
        if (! $lease instanceof TaskWorkspaceLeaseRecord || ($gatewayId !== '' && ! $gateway instanceof Node)) {
            $this->error('Unknown lease or recorded Gateway Node.');

            return self::FAILURE;
        }
        try {
            $recovery->release($lease, $reason, $gateway);
        } catch (TaskWorkspaceWaiting $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Recovery proof is unavailable. Ownership remains fenced.');

            return self::FAILURE;
        }
        $this->info('Lease '.$lease->id.' is released. Unknown tree changes and existing assistance remain unapproved.');

        return self::SUCCESS;
    }
}
