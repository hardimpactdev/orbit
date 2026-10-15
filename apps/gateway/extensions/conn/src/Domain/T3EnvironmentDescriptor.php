<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

final readonly class T3EnvironmentDescriptor
{
    public function __construct(
        public string $environmentId,
        public string $label,
        public string $serverVersion,
    ) {}
}
