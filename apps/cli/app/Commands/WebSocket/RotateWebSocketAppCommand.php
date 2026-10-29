<?php

declare(strict_types=1);

namespace App\Commands\WebSocket;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\WebSocket\RotateWebSocketAppRequest;

final class RotateWebSocketAppCommand extends WebSocketAppCommand
{
    #[\Override]
    protected $signature = 'websocket:app:rotate
        {name : WebSocket app name}
        {--force : Skip the destructive confirmation prompt}
        {--operation-key= : Reuse this key to retry the same change}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Rotate a Reverb app secret and close only that app’s sockets.';

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

        if (! $this->confirmAction(
            "Rotate WebSocket app [{$name}]? Its sockets reconnect. Synchronize and reload attached Instances afterward.",
            'WebSocket app rotation cancelled.',
            option: 'force',
            requiredCode: 'websocket.confirmation_required',
            requiredMessage: 'Use --force to confirm WebSocket app rotation.',
        )) {
            return self::FAILURE;
        }

        return $this->integrationOperation($connector, $repository, new RotateWebSocketAppRequest($name, $this->operationKey()), 'Rotate WebSocket app');
    }
}
