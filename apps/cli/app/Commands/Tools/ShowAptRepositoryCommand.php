<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tools\ShowAptRepositoryRequest;
use Orbit\Sdk\Responses\Tools\AptRepositoryResponse;

final class ShowAptRepositoryCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'tool:apt-repository-show {repository : Numeric apt repository ID} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show an apt repository.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $id = $this->positiveId('repository', 'Apt repository', 'tool.repository_id_invalid');
        if ($id === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->sendWithProgress($connector, new ShowAptRepositoryRequest($id), AptRepositoryResponse::class,
            ['Show apt repository', 'Loading apt repository', 'Loaded apt repository']);
        if (! $response instanceof AptRepositoryResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Apt repository [{$response->name}].", [
            'ID' => $response->id,
            'Node ID' => $response->nodeId,
            'Name' => $response->name,
            'Definition' => json_encode($response->definition, JSON_UNESCAPED_SLASHES),
            'Status' => $response->status,
            'Error code' => $response->errorCode,
            'Tools' => $response->toolCount,
            'Request ID' => $response->requestId,
        ]));

        return self::SUCCESS;
    }
}
