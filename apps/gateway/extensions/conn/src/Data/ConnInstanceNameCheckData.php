<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Orbit\Extensions\Conn\Domain\ConnInstanceNameReason;
use Spatie\LaravelData\Data;

final class ConnInstanceNameCheckData extends Data
{
    public function __construct(
        public string $name,
        public bool $available,
        public ?ConnInstanceNameReason $reason,
        public ?string $domain,
    ) {}
}
