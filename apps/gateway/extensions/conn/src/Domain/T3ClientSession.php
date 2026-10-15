<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

final readonly class T3ClientSession
{
    public function __construct(
        public string $sessionId,
        public ?string $label,
        public bool $current,
    ) {}
}
