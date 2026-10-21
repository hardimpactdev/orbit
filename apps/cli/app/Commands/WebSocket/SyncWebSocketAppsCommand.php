<?php

declare(strict_types=1);

namespace App\Commands\WebSocket;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Illuminate\Support\Sleep;
use Orbit\Sdk\Requests\WebSocket\ShowWebSocketOperationRequest;
use Orbit\Sdk\Requests\WebSocket\SyncWebSocketAppsRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketChangeResponse;

final class SyncWebSocketAppsCommand extends WebSocketAppCommand
{
    #[\Override]
    protected $signature = 'websocket:sync
        {--wait=60 : Maximum seconds to wait for every process}
        {--operation-key= : Reuse this key to retry the same sync}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Wait for Reverb to apply the current app registry without restarting it.';

    protected function operationKey(): ?string
    {
        $key = $this->option('operation-key');

        return is_string($key) ? $key : null;
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $wait = filter_var($this->option('wait'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3600]]);
        if ($wait === false) {
            $this->renderGatewayFailure('websocket.wait_invalid', 'Wait must be between 0 and 3600 seconds.');

            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $deadline = microtime(true) + $wait;
        $change = $this->send($connector, new SyncWebSocketAppsRequest($this->operationKey()), WebSocketChangeResponse::class);
        while ($change instanceof WebSocketChangeResponse && $change->operation->state === 'pending' && microtime(true) < $deadline) {
            Sleep::sleep(1);
            $change = $this->send($connector, new ShowWebSocketOperationRequest($change->operation->id), WebSocketChangeResponse::class);
        }
        if (! $change instanceof WebSocketChangeResponse) {
            return self::FAILURE;
        }
        $this->renderChange($change);

        return $change->operation->state === 'applied' ? self::SUCCESS : self::FAILURE;
    }
}
