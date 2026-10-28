<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Processes\RestartProcessRequest;

final class RestartProcessCommand extends ProcessActionCommand
{
    #[\Override]
    protected $signature = 'process:restart
        {process : Numeric process ID}
        {--idempotency-key= : Reuse a key to retry this Operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Restart one process.';

    protected function request(int $processId): GatewayRequest
    {
        return new RestartProcessRequest($processId, is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null);
    }

    protected function pastTense(): string
    {
        return 'restarted';
    }

    protected function progressLabels(): array
    {
        return ['Restart Process', 'Restarting Process', 'Restarted Process'];
    }
}
