<?php

declare(strict_types=1);

namespace App\Commands\Schedule;

use App\Commands\Schedule\Concerns\ResolvesScheduleSelection;
use App\Exceptions\GatewayApiException;

final class ScheduleRunCommand extends ScheduleGatewayCommand
{
    use ResolvesScheduleSelection;

    protected $signature = 'schedule:run
        {name? : Schedule name}
        {--app= : Filter by app scope}
        {--node= : Filter by node scope}
        {--json : Output JSON}';

    protected $description = 'Run one configured schedule immediately.';

    public function handle(): int
    {
        $scopeValidation = $this->validateScopeFilters();

        if ($scopeValidation !== null) {
            return $scopeValidation;
        }

        $name = $this->resolveScheduleName('Which schedule do you want to run?');

        if (is_int($name)) {
            return $name;
        }

        try {
            $response = $this->gatewayPost($this->scheduleScopePath('/api/schedules/'.rawurlencode($name).'/run'));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }
}
