<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Push;

/** The outcome of one APNs send attempt. */
final readonly class ApnsSendResult
{
    public function __construct(
        public int $status,
        public ?string $apnsId = null,
        public ?string $reason = null,
    ) {}
}
