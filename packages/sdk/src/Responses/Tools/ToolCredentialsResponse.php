<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Tools;

final readonly class ToolCredentialsResponse
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        public array $credentials = [],
    ) {}
}
