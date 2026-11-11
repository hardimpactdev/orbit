<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Orbit\Extensions\Conn\Domain\ConnProjectCheckStatus;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class ConnProjectCheckData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public ConnProjectCheckStatus $status,
        public string|Optional $code = new Optional,
        public string|Optional $message = new Optional,
    ) {}
}
