<?php

declare(strict_types=1);

namespace App\Services\Solo;

final readonly class SoloUpstreamError
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $meta,
        public int $status,
    ) {}
}
