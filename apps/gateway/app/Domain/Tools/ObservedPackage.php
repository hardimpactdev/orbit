<?php

declare(strict_types=1);

namespace App\Domain\Tools;

/** One package in a Node agent's `packages` fact, with the adoption block the fact alone shows. */
final readonly class ObservedPackage
{
    /** @param list<string> $versions the installed versions; adoption needs exactly one */
    public function __construct(
        public string $name,
        public array $versions,
        public bool $dependency,
        public ?string $block,
    ) {}

    /** The one installed version, or null when the fact shows none or several. */
    public function version(): ?string
    {
        return count($this->versions) === 1 ? $this->versions[0] : null;
    }
}
