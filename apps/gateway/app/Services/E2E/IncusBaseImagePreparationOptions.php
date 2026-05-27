<?php

declare(strict_types=1);

namespace App\Services\E2E;

final readonly class IncusBaseImagePreparationOptions
{
    public function __construct(
        public bool $force,
        public string $sourceImage,
        public string $baseImageAlias,
        public string $bootstrapUser,
        public int $cpus,
        public string $memory,
        public int $timeoutSeconds,
        public string $depsScriptPath,
    ) {}
}
