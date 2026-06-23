<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Vpn;

final readonly class VpnPasswordResponse
{
    /**
     * @param  array<string, mixed>  $vpn
     */
    public function __construct(
        public array $vpn,
    ) {}
}
