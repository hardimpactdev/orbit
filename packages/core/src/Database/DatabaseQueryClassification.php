<?php

declare(strict_types=1);

namespace Orbit\Core\Database;

final readonly class DatabaseQueryClassification
{
    public function __construct(
        public string $mode,
        public bool $requiresWriteMode,
    ) {}
}
