<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

abstract class ProcessActionCommand extends ProcessCommand
{
    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $processId = $this->positiveId('process', 'Process', 'process.id_invalid');

        if ($processId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $process = $this->send($connector, $this->request($processId), OperationResponse::class);

        if (! $process instanceof OperationResponse) {
            return self::FAILURE;
        }

        return $this->finishOperation($connector, $repository, $process, $this->progressLabels()[0]);
    }

    abstract protected function request(int $processId): GatewayRequest;

    abstract protected function pastTense(): string;

    /** @return array{0: string, 1: string, 2: string} */
    abstract protected function progressLabels(): array;
}
