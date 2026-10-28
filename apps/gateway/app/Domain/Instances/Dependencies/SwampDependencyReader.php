<?php

declare(strict_types=1);

namespace App\Domain\Instances\Dependencies;

interface SwampDependencyReader
{
    /** Returns JSON content from the native resource. */
    public function read(string $dataName, ?int $version, int $maxBytes): string;
}
