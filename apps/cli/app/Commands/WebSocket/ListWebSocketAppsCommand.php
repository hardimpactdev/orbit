<?php

declare(strict_types=1);

namespace App\Commands\WebSocket;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\WebSocket\ListWebSocketAppsRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppResponse;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppsResponse;

final class ListWebSocketAppsCommand extends WebSocketAppCommand
{
    #[\Override]
    protected $signature = 'websocket:app:list
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List the Reverb apps on the websocket role.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListWebSocketAppsRequest,
            WebSocketAppsResponse::class,
            ['List WebSocket apps', 'Loading WebSocket apps', 'Loaded WebSocket apps'],
        );

        if (! $response instanceof WebSocketAppsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Name', 'App ID', 'Key', 'Instance IDs'],
            array_map(
                static fn (WebSocketAppResponse $app): array => [
                    $app->name,
                    $app->appId,
                    $app->key,
                    $app->instanceIds === [] ? null : implode(', ', $app->instanceIds),
                ],
                $response->apps,
            ),
            'No WebSocket apps.',
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
