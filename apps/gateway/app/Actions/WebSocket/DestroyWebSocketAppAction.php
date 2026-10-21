<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Domain\Shared\ResourceOperationException;
use App\Models\WebSocketApp;

final readonly class DestroyWebSocketAppAction
{
    public function __construct(private ConvergeWebSocketServerAction $server) {}

    /** A failed converge keeps the record deleted; the role's next converge stops serving the app. */
    public function execute(WebSocketApp $app): void
    {
        if ($app->isGateway()) {
            throw new ResourceOperationException(
                errorCode: 'websocket.app_reserved',
                message: "WebSocket app [{$app->name}] belongs to the Gateway.",
                status: 409,
            );
        }

        if ($app->targets()->exists()) {
            throw new ResourceOperationException(
                errorCode: 'websocket.app_in_use',
                message: "WebSocket app [{$app->name}] is still on an Instance. Remove it from each Instance first.",
                status: 409,
            );
        }

        $app->delete();
        $this->server->execute();
    }
}
