<?php

declare(strict_types=1);

namespace App\Commands\Operations;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Operations\ResumeOperationRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class ResumeOperationCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'operation:resume
        {operation : Numeric operation ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Resume a Swamp Operation stopped at its reclaim limit.';

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

        $operation = $this->sendWithProgress($connector, new ResumeOperationRequest($operationId), OperationResponse::class, ['Resume operation', 'Resuming operation', 'Queued operation to resume']);

        if (! $operation instanceof OperationResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($operation->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Operation: {$operation->id}", OperationOutput::detail($operation)));

        $this->writeHumanMessage("Follow it with: orbit operation:show {$operation->id}");

        $this->writeHumanMessage('Request ID: '.$operation->requestId);

        return self::SUCCESS;
    }
}
