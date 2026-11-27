<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tools\ListAptRepositoriesRequest;
use Orbit\Sdk\Responses\Tools\AptRepositoriesResponse;
use Orbit\Sdk\Responses\Tools\AptRepositoryResponse;

final class ListAptRepositoriesCommand extends ToolCommand
{
    #[\Override]
    protected $signature = 'tool:apt-repository-list {--node= : Numeric target node ID} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List the apt repositories of a node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $node = $this->nodeId();
        if ($node === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->sendWithProgress($connector, new ListAptRepositoriesRequest($node), AptRepositoriesResponse::class,
            ['List apt repositories', 'Loading apt repositories', 'Loaded apt repositories']);
        if (! $response instanceof AptRepositoriesResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }
        $rows = array_map(static fn (AptRepositoryResponse $item): array => [
            $item->id, $item->name, self::joined($item->definition['uris'] ?? null), self::joined($item->definition['suites'] ?? null),
            $item->status, $item->toolCount, $item->errorCode,
        ], $response->repositories);
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(['ID', 'Name', 'URIs', 'Suites', 'Status', 'Tools', 'Error'], $rows));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    /** The strings of a definition list, space separated. */
    private static function joined(mixed $value): string
    {
        return is_array($value) ? implode(' ', array_filter($value, is_string(...))) : '';
    }
}
