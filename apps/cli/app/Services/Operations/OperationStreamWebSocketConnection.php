<?php

declare(strict_types=1);

namespace App\Services\Operations;

final readonly class OperationStreamWebSocketConnection
{
    /**
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $appKey,
        public int $timeout,
        public ?string $caPemPath = null,
    ) {}
}
