<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use Carbon\CarbonImmutable;
use SensitiveParameter;

final readonly class T3PairingCredential
{
    public function __construct(
        public string $id,
        #[SensitiveParameter] public string $credential,
        public CarbonImmutable $expiresAt,
    ) {}
}
