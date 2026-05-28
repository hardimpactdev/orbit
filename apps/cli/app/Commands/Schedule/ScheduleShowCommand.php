<?php

declare(strict_types=1);

namespace App\Commands\Schedule;

use App\Commands\Concerns\ResolvesHostContext;
use App\Commands\GatewayCommand;
use App\Commands\Schedule\Concerns\ResolvesScheduleSelection;
use App\Exceptions\GatewayApiException;

final class ScheduleShowCommand extends GatewayCommand
{
    use ResolvesHostContext;
    use ResolvesScheduleSelection;

    protected $signature = 'schedule:show
        {name? : Schedule name}
        {--app= : Filter by app scope}
        {--node= : Filter by node scope}
        {--json}';

    protected $description = 'Show one configured schedule.';

    public function handle(): int
    {
        if ($this->hasMutuallyExclusiveOptions('app', 'node')) {
            return $this->renderFailure('validation_failed', 'The schedule filters are mutually exclusive.', ['fields' => ['app', 'node']]);
        }

        $name = $this->resolveScheduleName('Which schedule do you want to inspect?');

        if (is_int($name)) {
            return $name;
        }

        try {
            $response = $this->gatewayGet('/api/schedules/'.rawurlencode($name), $this->filledQuery([
                'app' => $this->resolvedScheduleApp(),
                'node' => $this->resolvedScheduleNode(),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
