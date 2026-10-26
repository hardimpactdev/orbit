<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/** What the release layout holds now: the current release, its state, and the prepared and incomplete releases. */
final readonly class GatewayReleaseInventory
{
    /**
     * @param  string  $state  `adopted`, `in_place`, or `unreadable`
     * @param  array<string, string>  $commits  the full commit of each prepared release, by id
     * @param  array<string, int>  $incomplete  the modification time of each release directory without `REVISION`, by id
     * @param  list<string>  $retained  the prepared release ids, newest first
     */
    public function __construct(
        public ?string $current,
        public string $state,
        public bool $superseded,
        public array $commits,
        public array $incomplete,
        public array $retained,
    ) {}
}
