<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

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

        $schedule = $this->send($connector, $this->request($scheduleId), OperationResponse::class);

        if (! $schedule instanceof OperationResponse) {
            return self::FAILURE;
        }

        return $this->finishOperation($connector, $repository, $schedule, $this->progressLabels()[0]);
    }

    abstract protected function request(string $scheduleId): GatewayRequest;

    /** @return array{0: string, 1: string, 2: string} */
    abstract protected function progressLabels(): array;
}
