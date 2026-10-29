<?php

declare(strict_types=1);

namespace App\Commands\WebSocket;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\WebSocket\DestroyWebSocketAppRequest;

final class DestroyWebSocketAppCommand extends WebSocketAppCommand
{
    #[\Override]
    protected $signature = 'websocket:app:destroy
        {name : WebSocket app name}
        {--force : Skip the destructive confirmation prompt}
        {--operation-key= : Reuse this key to retry the same change}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a Reverb app that no Instance uses. Reverb stops serving it.';

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
            "Destroy WebSocket app [{$name}]? Reverb stops serving it, and its clients disconnect.",
            'WebSocket app destruction cancelled.',
            option: 'force',
            requiredCode: 'websocket.confirmation_required',
            requiredMessage: 'Use --force to confirm WebSocket app destruction.',
        )) {
            return self::FAILURE;
        }

        return $this->integrationOperation($connector, $repository, new DestroyWebSocketAppRequest($name, $this->operationKey()), 'Destroy WebSocket app');
    }
}
