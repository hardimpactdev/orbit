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
     * @param  list<string>  $parked  the full commits of the stopped container releases that a rollback can start: old and earlier
     * @param  list<string>  $verified  the full commits of the container releases that passed public verification
     */
    public function __construct(
        public ?string $current,
        public string $state,
        public bool $superseded,
        public array $commits,
        public array $incomplete,
        public array $retained,
        public bool $hostRetired = false,
        public array $parked = [],
        public array $verified = [],
    ) {}
}
