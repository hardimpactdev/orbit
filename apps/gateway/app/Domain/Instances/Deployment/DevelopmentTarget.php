<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

/** The fetched commit of a default's branch. */
final readonly class DevelopmentTarget
{
    public function __construct(
        public string $commit,
    ) {}
}
