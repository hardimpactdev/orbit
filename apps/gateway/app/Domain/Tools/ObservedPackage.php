<?php

declare(strict_types=1);

namespace App\Domain\Tools;

/** One package in a Node agent's `packages` fact, with the adoption block the fact alone shows. */
final readonly class ObservedPackage
{
    /**
     * @param  list<string>  $versions  the installed versions
     * @param  string|null  $linked  the one of them Homebrew links, which is the installed version when several are present
     */
    public function __construct(
        public string $name,
        public array $versions,
        public ?string $linked,
        public bool $dependency,
        public ?string $block,
    ) {}

    /** The installed version: the linked one, else the only one; null when the fact shows none, or several and no link. */
    public function version(): ?string
    {
        return $this->linked ?? (count($this->versions) === 1 ? $this->versions[0] : null);
    }
}
