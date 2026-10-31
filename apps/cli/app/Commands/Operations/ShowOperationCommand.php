<?php

declare(strict_types=1);

namespace App\Commands\Operations;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class ShowOperationCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'operation:show
        {operation : Numeric operation ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show a parent Operation and its native result.';

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

        $operation = $this->sendWithProgress($connector, new ShowOperationRequest($operationId), OperationResponse::class, ['Show operation', 'Loading operation', 'Loaded operation']);

        if (! $operation instanceof OperationResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($operation->toArray());

            return self::SUCCESS;
        }

        $this->render($operation);

        return self::SUCCESS;
    }

    /** Displays the parent Operation record. */
    private function render(OperationResponse $operation): void
    {
        $renderer = $this->humanRenderer();
        ConsoleWriter::write($this->output, $renderer->detail("Operation: {$operation->id}", OperationOutput::detail($operation)));
        ConsoleWriter::write($this->output, "Request ID: {$operation->requestId}\n");
    }
}
