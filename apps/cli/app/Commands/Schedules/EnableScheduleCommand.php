<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Schedules\EnableScheduleRequest;

final class EnableScheduleCommand extends ScheduleItemCommand
{
    #[\Override]
    protected $signature = 'schedule:enable
        {schedule : Schedule UUID}
        {--idempotency-key= : Reuse a key to retry this Operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Enable and start one installed Instance Schedule timer.';

    protected function request(string $scheduleId): GatewayRequest
    {
        return new EnableScheduleRequest($scheduleId, is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null);
    }

    protected function progressLabels(): array
    {
        return ['Enable Schedule', 'Enabling Schedule', 'Enabled Schedule'];
    }
}
