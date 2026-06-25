<?php

declare(strict_types=1);

namespace App\Commands\Process;

final readonly class ProcessUpdateValidationFailure
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $field,
        public string $message,
        public array $meta = [],
    ) {}
}
