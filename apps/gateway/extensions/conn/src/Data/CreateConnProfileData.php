<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Spatie\LaravelData\Data;

final class CreateConnProfileData extends Data
{
    public function __construct(
        public string $name,
    ) {}
}
