<?php

declare(strict_types=1);

namespace App\Commands\WebSocket;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\WebSocket\DestroyWebSocketAppRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppResponse;

final class DestroyWebSocketAppCommand extends WebSocketAppCommand
{
    #[\Override]
    protected $signature = 'websocket:app:destroy
        {name : WebSocket app name}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a Reverb app that no Instance uses. Reverb stops serving it.';

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

        if (! $this->confirmAction(
            "Destroy WebSocket app [{$name}]? Reverb stops serving it, and its clients disconnect.",
            'WebSocket app destruction cancelled.',
            option: 'force',
            requiredCode: 'websocket.confirmation_required',
            requiredMessage: 'Use --force to confirm WebSocket app destruction.',
        )) {
            return self::FAILURE;
        }

        $app = $this->sendWithProgress(
            $connector,
            new DestroyWebSocketAppRequest($name),
            WebSocketAppResponse::class,
            ['Destroy WebSocket app', 'Destroying WebSocket app', 'Destroyed WebSocket app'],
        );

        if (! $app instanceof WebSocketAppResponse) {
            return self::FAILURE;
        }

        return $this->renderApp($app, "WebSocket app [{$app->name}] destroyed.");
    }
}
