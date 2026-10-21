<?php

declare(strict_types=1);

namespace App\Commands\WebSocket;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\WebSocket\CreateWebSocketAppRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketChangeResponse;

final class CreateWebSocketAppCommand extends WebSocketAppCommand
{
    #[\Override]
    protected $signature = 'websocket:app:create
        {name : WebSocket app name}
        {--operation-key= : Reuse this key to retry the same change}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a Reverb app on the websocket role. The Gateway keeps its secret and never returns it.';

    protected function operationKey(): ?string
    {
        $key = $this->option('operation-key');

        return is_string($key) ? $key : null;
    }

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
            new CreateWebSocketAppRequest($name, $this->operationKey()),
            WebSocketChangeResponse::class,
            ['Create WebSocket app', 'Creating WebSocket app', 'Saved WebSocket app change'],
        );

        if (! $app instanceof WebSocketChangeResponse) {
            return self::FAILURE;
        }

        return $this->renderChange($app);
    }
}
