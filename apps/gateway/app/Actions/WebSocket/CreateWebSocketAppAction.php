<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Domain\WebSocket\WebSocketAppIssuer;
use App\Models\WebSocketApp;

final readonly class CreateWebSocketAppAction
{
    public function __construct(
        private WebSocketAppIssuer $issuer,
        private ConvergeWebSocketServerAction $server,
    ) {}

    /** A failed converge keeps the app; the role's next converge serves it. */
    public function execute(string $name): WebSocketApp
    {
        $app = $this->issuer->issue($name);
        $this->server->execute();

        return $app;
    }
}
