<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Profile;

final readonly class ProfileResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}
}
