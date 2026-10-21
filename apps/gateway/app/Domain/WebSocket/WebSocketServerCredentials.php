<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use SensitiveParameter;

/**
 * What the Reverb server on the `websocket` role's Node boots with: the Laravel `APP_KEY` of the server
 * app, and every Reverb app it serves, ordered by name.
 */
final readonly class WebSocketServerCredentials
{
    /** @param  list<WebSocketCredentials>  $apps */
    public function __construct(
        #[SensitiveParameter]
        public string $laravelAppKey,
        public array $apps,
    ) {}

    public function __debugInfo(): array
    {
        return [
            'laravelAppKey' => '[PROTECTED]',
            'apps' => $this->apps,
        ];
    }
}
