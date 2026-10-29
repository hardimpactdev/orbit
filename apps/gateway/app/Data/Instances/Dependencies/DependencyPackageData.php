<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencyPackageData extends Data
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public bool $direct,
        public array $scopes,
        public string $origin,
    ) {}
}
