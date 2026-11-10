<?php

declare(strict_types=1);

namespace App\Jobs\Fleet;

use App\Actions\Fleet\AdmitAgentToolsAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Admits the daily `fleet.agent_tools` run (ADR 0255). A run that cannot start today, because one is still open, a reboot pass
 * runs or there is nothing to update, waits for the next day.
 */
final class AdmitAgentTools implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(AdmitAgentToolsAction $admit): void
    {
        try {
            $admit->execute();
        } catch (ResourceOperationException $refusal) {
            if (! in_array($refusal->errorCode, ['fleet.agent_tools_busy', 'fleet.agent_tools_waiting', 'fleet.agent_tools_not_ready'], true)) {
                throw $refusal;
            }
            Log::info('No agent tool update was admitted.', ['error_code' => $refusal->errorCode]);
        }
    }
}
