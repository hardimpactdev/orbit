<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

final readonly class ArtifactPin
{
    public function __construct(
        public string $location,
        public string $algorithm,
        public string $digest,
    ) {}
}
