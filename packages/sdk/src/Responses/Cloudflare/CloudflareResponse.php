<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Cloudflare;

final readonly class CloudflareResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $data,
        public array $meta = [],
    ) {}
}
