<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleMutationResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleResponse;

abstract class ScheduleCommand extends GatewayCommand
{
    use FollowsOperations;

    protected function finishMutation(GatewayConnector $connector, GatewayConfigRepository $repository, ScheduleMutationResponse $response, string $title): int
    {
        if ($response->result instanceof OperationResponse) {
            return $this->finishOperation($connector, $repository, $response->result, $title);
        }

        $this->renderSchedule($response->result);

        return self::SUCCESS;
    }

    protected function finishOperation(GatewayConnector $connector, GatewayConfigRepository $repository, OperationResponse $operation, string $title): int
    {
        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE : $this->followOperation($connector, $profile, $operation, $title, 'Schedule');
    }

    protected function renderSchedule(ScheduleResponse $schedule): void
    {
        if ($this->option('json') === true) {
            $this->writeJson($schedule->toArray());

            return;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Schedule [{$schedule->name}].", [
            'UUID' => $schedule->id,
            'Target' => "{$schedule->targetType}:{$schedule->targetId}",
            'Name' => $schedule->name,
            'Calendar' => $schedule->calendar,
            'Command' => $schedule->command,
            'Timeout seconds' => $schedule->timeoutSeconds,
            'Desired timer state' => $schedule->desiredTimerState,
            'Lifecycle state' => $schedule->status,
            'Failed step' => $schedule->failedStep,
            'Error code' => $schedule->errorCode,
            'Last run at' => $schedule->lastRunAt,
            'Last run status' => $schedule->lastRunStatus,
            'Request ID' => $schedule->requestId,
        ]));
    }
}
