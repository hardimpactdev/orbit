<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Processes\StopProcessRequest;

final class StopProcessCommand extends ProcessActionCommand
{
    #[\Override]
    protected $signature = 'process:stop
        {process : Numeric process ID}
        {--idempotency-key= : Reuse a key to retry this Operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Stop one process.';

    protected function request(int $processId): GatewayRequest
    {
        return new StopProcessRequest($processId, is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null);
    }

    protected function pastTense(): string
    {
        return 'stopped';
    }

    protected function progressLabels(): array
    {
        return ['Stop Process', 'Stopping Process', 'Stopped Process'];
    }
}
