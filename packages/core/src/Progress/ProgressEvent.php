<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

final readonly class ProgressEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ProgressEventType $type,
        public array $payload = [],
    ) {}
}
