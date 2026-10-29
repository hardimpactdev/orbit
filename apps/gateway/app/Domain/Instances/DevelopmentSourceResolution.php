<?php

declare(strict_types=1);

namespace App\Domain\Instances;

final readonly class DevelopmentSourceResolution
{
    public function __construct(
        public string $branch,
        public string $startingCommit,
    ) {}

    public static function matchesBranch(mixed $branch, string $selected, mixed $seedCommit, mixed $prepareId): bool
    {
        return $branch === $selected || (is_string($seedCommit) && is_string($prepareId) && $prepareId !== ''
            && $branch === 'orbit/instance/'.$prepareId);
    }
}
