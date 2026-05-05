<?php

declare(strict_types=1);

namespace App\Services\E2E;

final readonly class HcloudDockerE2ERunOptions
{
    public function __construct(
        public bool $force,
        public bool $keep,
        public string $sourceImage,
        public string $serverType,
        public string $location,
        /** @var array<string, int> */
        public array $resourceSlots,
        public int $slotWaitSeconds,
        public int $slotStaleSeconds,
        public string $prefix,
        public string $kind,
        public int $processes,
        public int $timeoutSeconds,
    ) {}
}
