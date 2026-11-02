<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Schedules\DisableScheduleRequest;

final class DisableScheduleCommand extends ScheduleItemCommand
{
    #[\Override]
    protected $signature = 'schedule:disable
        {schedule : Schedule UUID}
        {--idempotency-key= : Reuse a key to retry this Operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove the trigger of one Schedule; its next fires do not start.';

    protected function request(string $scheduleId): GatewayRequest
    {
        return new DisableScheduleRequest($scheduleId, is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null);
    }

    protected function progressLabels(): array
    {
        return ['Disable Schedule', 'Disabling Schedule', 'Disabled Schedule'];
    }
}
