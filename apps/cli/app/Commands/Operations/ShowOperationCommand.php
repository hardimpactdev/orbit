<?php

declare(strict_types=1);

namespace App\Commands\Operations;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\TerminalText;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Operations\OperationStepResponse;

final class ShowOperationCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'operation:show
        {operation : Numeric operation ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one operation with its steps and their output.';

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

    /** The detail tree, the steps table, and the output of each step that has output. */
    private function render(OperationResponse $operation): void
    {
        $renderer = $this->humanRenderer();
        ConsoleWriter::write($this->output, $renderer->detail("Operation: {$operation->id}", OperationOutput::detail($operation)));
        ConsoleWriter::write($this->output, $renderer->table(
            ['STEP', 'STATUS', 'STARTED', 'FINISHED', 'ERROR'],
            array_map(static fn (OperationStepResponse $step): array => [
                $step->name,
                $step->status,
                $step->startedAt ?? '',
                $step->finishedAt ?? '',
                $step->errorCode ?? '',
            ], $operation->steps),
            'No steps yet.',
        ));

        foreach ($operation->steps as $step) {
            if ($step->output === null) {
                continue;
            }

            $heading = $step->outputTruncated ? "Output of {$step->name} (last 8 KiB):" : "Output of {$step->name}:";
            ConsoleWriter::write($this->output, $heading."\n".TerminalText::safe(rtrim($step->output, "\n"))."\n");
        }

        ConsoleWriter::write($this->output, "Request ID: {$operation->requestId}\n");
    }
}
