<?php

declare(strict_types=1);

namespace App\Services\E2E;

final readonly class IncusE2EImagePreparationOptions
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public array $roles,
        public bool $force,
        public string $sourceImage,
        public string $blankImageAlias,
        public string $controlImageAlias,
        public string $gatewayImageAlias,
        public string $devappImageAlias,
        public string $prodappImageAlias,
        public string $bootstrapUser,
        public string $controlUser,
        public string $installScriptPath,
        public string $serverType,
        public int $cpus,
        public string $memory,
        public int $timeoutSeconds,
    ) {}
}
