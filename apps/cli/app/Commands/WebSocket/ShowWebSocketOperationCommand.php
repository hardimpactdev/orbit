<?php

declare(strict_types=1);

namespace App\Commands\WebSocket;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\WebSocket\ShowWebSocketOperationRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketChangeResponse;

final class ShowWebSocketOperationCommand extends WebSocketAppCommand
{
    #[\Override]
    protected $signature = 'websocket:operation:show {id : Operation UUID} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show which Reverb processes have applied an app change.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $id = $this->stringArgument('id', 'Operation UUID', 'websocket.operation_required');
        if ($id === null || ! Str::isUuid($id)) {
            $this->renderGatewayFailure('websocket.operation_invalid', 'Operation ID must be a UUID.');

            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $change = $this->send($connector, new ShowWebSocketOperationRequest($id), WebSocketChangeResponse::class);

        return $change instanceof WebSocketChangeResponse ? $this->renderChange($change) : self::FAILURE;
    }
}
