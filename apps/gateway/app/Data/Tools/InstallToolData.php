<?php

declare(strict_types=1);

namespace App\Data\Tools;

final readonly class InstallToolData
{
    /** @param string|null $repository the name of the Node's apt repository */
    public function __construct(
        public int $nodeId,
        public string $manager,
        public string $package,
        public ?string $versionConstraint,
        public ?string $repository = null,
        public ?string $desiredVersion = null,
    ) {}
}
