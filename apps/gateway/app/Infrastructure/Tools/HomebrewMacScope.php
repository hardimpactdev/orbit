<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

/**
 * The enrolled account's Homebrew prefix and resolved home directory.
 */
final readonly class HomebrewMacScope
{
    public function __construct(
        public string $prefix,
        public string $home,
    ) {}
}
