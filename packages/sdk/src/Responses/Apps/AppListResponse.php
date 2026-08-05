<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Apps;

final readonly class AppListResponse
{
    /**
     * @param  list<array<string, mixed>>  $apps
     */
    public function __construct(
        public array $apps,
    ) {}
}
