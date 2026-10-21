<?php

declare(strict_types=1);

namespace App\Commands\WebSocket;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\WebSocket\ShowWebSocketAppRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppResponse;

final class ShowWebSocketAppCommand extends WebSocketAppCommand
{
    #[\Override]
    protected $signature = 'websocket:app:show
        {name : WebSocket app name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one Reverb app and the Instances that use it.';

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
            new ShowWebSocketAppRequest($name),
            WebSocketAppResponse::class,
            ['Show WebSocket app', 'Loading WebSocket app', 'Loaded WebSocket app'],
        );

        if (! $app instanceof WebSocketAppResponse) {
            return self::FAILURE;
        }

        return $this->renderApp($app, "WebSocket app [{$app->name}].");
    }
}
