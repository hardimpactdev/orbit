<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Schedules\ScheduleMutationResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleResponse;

abstract class ScheduleItemCommand extends ScheduleUuidCommand
{
    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $scheduleId = $this->scheduleId();

        if ($scheduleId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $schedule = $this->send($connector, $this->request($scheduleId), $this->responseClass());

        if ($schedule instanceof ScheduleResponse) {
            $this->renderSchedule($schedule);

            return self::SUCCESS;
        }

        if (! $schedule instanceof ScheduleMutationResponse) {
            return self::FAILURE;
        }

        return $this->finishMutation($connector, $repository, $schedule, $this->progressLabels()[0]);
    }

    abstract protected function request(string $scheduleId): GatewayRequest;

    /** @return class-string<ScheduleResponse|ScheduleMutationResponse> */
    protected function responseClass(): string
    {
        return ScheduleMutationResponse::class;
    }

    /** @return array{0: string, 1: string, 2: string} */
    abstract protected function progressLabels(): array;
}
