<?php

declare(strict_types=1);

namespace App\Support\Streaming;

final readonly class StreamFlusher
{
    public function __construct(
        private string $sapi = PHP_SAPI,
    ) {}

    public function flush(): void
    {
        if (! in_array($this->sapi, ['fpm-fcgi', 'cli-server', 'frankenphp'], strict: true)) {
            return;
        }

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
