<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use LogicException;

/** Bounded, sleeping reads for retained Gateway callers. Never executes or cancels work. */
final readonly class AwaitOperation
{
    public function execute(Operation $operation, int $timeoutSeconds = 30): Operation
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('AwaitOperation must run after its admission transaction commits.');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new InvalidArgumentException('An Operation wait must be between 1 and 120 seconds.');
        }
        $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;
        do {
            $operation->refresh();
            if ($operation->status->terminal()) {
                return $operation;
            }
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                break;
            }
            Sleep::usleep(min(1_000_000, max(1, intdiv($remaining, 1000))));
        } while (true);

        throw new ResourceOperationException('operation.await_timeout', 'The Operation has not ended within the caller wait.', 409,
            details: ['operation_id' => $operation->id]);
    }
}
