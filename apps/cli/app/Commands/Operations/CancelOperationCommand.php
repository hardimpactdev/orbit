<?php

declare(strict_types=1);

namespace App\Commands\Operations;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Operations\CancelOperationRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class CancelOperationCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'operation:cancel
        {operation : Numeric operation ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Ask one operation to stop. A running operation stops before its next step.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $operationId = $this->positiveId('operation', 'Operation', 'operation.id_invalid');

        if ($operationId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $operation = $this->sendWithProgress($connector, new CancelOperationRequest($operationId), OperationResponse::class, ['Cancel operation', 'Cancelling operation', 'Asked operation to stop']);

        if (! $operation instanceof OperationResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($operation->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Operation: {$operation->id}", OperationOutput::detail($operation)));

        if (! $operation->terminal()) {
            $this->writeHumanMessage("The operation stops before its next step. Follow it with: orbit operation:show {$operation->id}");
        }

        $this->writeHumanMessage('Request ID: '.$operation->requestId);

        return self::SUCCESS;
    }
}
