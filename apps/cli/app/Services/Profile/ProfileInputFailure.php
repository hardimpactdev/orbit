<?php

declare(strict_types=1);

namespace App\Services\Profile;

final readonly class ProfileInputFailure
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $meta,
    ) {}

    public function isMissingTarget(): bool
    {
        return $this->code === 'validation_failed'
            && ($this->meta['field'] ?? null) === 'target'
            && ($this->meta['reason'] ?? null) === 'missing_required_input';
    }
}
