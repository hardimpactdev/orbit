<?php

declare(strict_types=1);

namespace App\Commands\Schedule;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;

final class ScheduleListCommand extends GatewayCommand
{
    use ResolvesHostContext;

    #[\Override]
    protected $signature = 'schedule:list
        {--app= : Filter by app scope}
        {--node= : Filter by node scope}
        {--json}';

    #[\Override]
    protected $description = 'List configured schedules.';

    public function handle(): int
    {
        if ($this->hasMutuallyExclusiveOptions('app', 'node')) {
            return $this->renderFailure('validation_failed', 'The schedule filters are mutually exclusive.', ['fields' => ['app', 'node']]);
        }

        try {
            $response = $this->gatewayGet('/api/schedules', $this->filledQuery([
                'app' => $this->stringOption('app'),
                'node' => $this->stringOption('node'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
