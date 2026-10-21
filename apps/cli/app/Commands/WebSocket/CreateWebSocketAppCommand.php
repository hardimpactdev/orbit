<?php

declare(strict_types=1);

namespace App\Commands\WebSocket;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\WebSocket\CreateWebSocketAppRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppResponse;

final class CreateWebSocketAppCommand extends WebSocketAppCommand
{
    #[\Override]
    protected $signature = 'websocket:app:create
        {name : WebSocket app name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a Reverb app on the websocket role. The Gateway keeps its secret and never returns it.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $name = $this->appName('name');

        if ($name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $app = $this->sendWithProgress(
            $connector,
            new CreateWebSocketAppRequest($name),
            WebSocketAppResponse::class,
            ['Create WebSocket app', 'Creating WebSocket app', 'Created WebSocket app'],
        );

        if (! $app instanceof WebSocketAppResponse) {
            return self::FAILURE;
        }

        return $this->renderApp($app, "WebSocket app [{$app->name}] created.");
    }
}
