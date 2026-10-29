<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use SensitiveParameter;

/** Protected bootstrap configuration; app credentials arrive only through the registry API. */
final readonly class WebSocketServerCredentials
{
    public function __construct(
        #[SensitiveParameter] public string $laravelAppKey,
        public string $processId,
        #[SensitiveParameter] public string $registryToken,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['processId' => $this->processId];
    }
}
