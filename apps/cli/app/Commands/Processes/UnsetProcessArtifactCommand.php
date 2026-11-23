<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Processes\UnsetProcessArtifactRequest;
use Orbit\Sdk\Responses\Processes\ProcessArtifactRemovedResponse;

final class UnsetProcessArtifactCommand extends ProcessCommand
{
    #[\Override]
    protected $signature = 'process:artifact:unset
        {process : Numeric process ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove the release artifact record of a Process; the Node is left alone.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $processId = $this->positiveId('process', 'Process', 'process.id_invalid');

        if ($processId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send($connector, new UnsetProcessArtifactRequest($processId), ProcessArtifactRemovedResponse::class);

        if (! $response instanceof ProcessArtifactRemovedResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail(
            $response->removed ? "Process [{$response->name}] artifact removed." : "Process [{$response->name}] had no artifact.",
            ['ID' => $response->id, 'Request ID' => $response->requestId],
        ));

        return self::SUCCESS;
    }
}
