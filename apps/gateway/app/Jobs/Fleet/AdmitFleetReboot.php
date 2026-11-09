<?php

declare(strict_types=1);

namespace App\Jobs\Fleet;

use App\Actions\Fleet\AdmitFleetRebootAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Admits the nightly `fleet.reboot` pass when the window opens (ADR 0251). The pass waits a minute at a time while a fleet
 * rollout or a Gateway release runs, as they share the window. Each Node still checks the window before its drain.
 */
final class AdmitFleetReboot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A minute apart, for at most the first two hours of the window: a pass that cannot start then waits for the next night. */
    public int $tries = 120;

    public int $timeout = 120;

    public int $uniqueFor = 7200;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(AdmitFleetRebootAction $admit): void
    {
        try {
            $admit->execute(window: true);
        } catch (ResourceOperationException $refusal) {
            if ($refusal->errorCode === 'fleet.reboot_waiting') {
                $this->release(60);

                return;
            }
            if (! in_array($refusal->errorCode, ['fleet.reboot_busy', 'fleet.reboot_not_ready'], true)) {
                throw $refusal;
            }
            Log::info('No fleet reboot pass was admitted.', ['error_code' => $refusal->errorCode]);
        }
    }
}
