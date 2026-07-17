<?php

declare(strict_types=1);

namespace App\Data\Php;

final readonly class PhpRuntimeImageInventory
{
    /**
     * @param  list<string>  $images
     * @param  list<string>  $versions
     */
    public function __construct(
        public string $status,
        public array $images = [],
        public array $versions = [],
        public ?string $error = null,
    ) {}

    public function confirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}
