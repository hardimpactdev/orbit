<?php

declare(strict_types=1);

namespace App\Services\E2E;

final readonly class HcloudE2EImagePreparationOptions
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public array $roles,
        public bool $force,
        public bool $keep,
        public string $sourceImage,
        public string $serverType,
        public string $location,
        public string $phpVersion,
        public string $bootstrapUser,
        public string $controlUser,
        public string $prefix,
        public string $controlDescription,
        public string $gatewayDescription,
        public string $devappDescription,
        public int $timeoutSeconds,
    ) {}
}
