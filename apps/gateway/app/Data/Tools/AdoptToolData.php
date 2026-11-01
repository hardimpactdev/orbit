<?php

declare(strict_types=1);

namespace App\Data\Tools;

final readonly class AdoptToolData
{
    /** @param array<string, mixed>|null $repository */
    public function __construct(
        public int $nodeId,
        public string $manager,
        public string $package,
        public ?string $versionConstraint,
        public ?array $repository = null,
    ) {}
}
