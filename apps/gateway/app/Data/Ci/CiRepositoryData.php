<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** One repository whose jobs the runner view read; `truncated` when the Gateway did not read every run of the window. */
final class CiRepositoryData extends Data
{
    public function __construct(
        public string $repository,
        public ?string $error_code,
        public bool $truncated,
    ) {}
}
