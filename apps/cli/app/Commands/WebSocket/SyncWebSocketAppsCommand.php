<?php

declare(strict_types=1);

namespace App\Commands\WebSocket;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Illuminate\Support\Sleep;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\WebSocket\SyncWebSocketAppsRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

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
        $operation = $this->send($connector, new SyncWebSocketAppsRequest($this->operationKey()), OperationResponse::class);
        while ($operation instanceof OperationResponse && ! $operation->terminal() && microtime(true) < $deadline) {
            Sleep::sleep(min(1, max(0, $deadline - microtime(true))));
            $operation = $this->send($connector, new ShowOperationRequest($operation->id), OperationResponse::class);
        }
        if (! $operation instanceof OperationResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($operation->toArray());
        } else {
            $this->writeHumanMessage('Operation '.$operation->id.': '.$operation->status.'.');
            if (! $operation->terminal()) {
                $this->writeHumanMessage('Follow it with: orbit operation:show '.$operation->id);
            }
        }

        return $operation->succeeded() ? self::SUCCESS : self::FAILURE;
    }
}
