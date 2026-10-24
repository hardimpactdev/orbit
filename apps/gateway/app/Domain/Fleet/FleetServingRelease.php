<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use Throwable;

/**
 * What the Gateway serves right now, read from disk on every call rather than from this process's own
 * configuration. A rollout process started from an older release would otherwise keep rolling out the
 * older desired state after a newer release went current.
 *
 * - `adopted`: the current path links to a release, and `commit` is that release's full commit.
 * - `in_place`: the current path is a checkout; it has no release records, and `commit` is null.
 * - `unreadable`: the configured layout is invalid, or the link names no complete release. The rollout
 *   fails closed and rolls out nothing.
 */
final readonly class FleetServingRelease
{
    /** @param string|null $ownApplicationPath  The `apps/gateway` directory this process runs from; the base path by default. */
    public function __construct(private ?string $ownApplicationPath = null) {}

    /**
     * Whether the current link serves another release directory than the one this process runs from. Only a
     * Gateway in the release layout can be superseded; an unreadable layout is not, and the gate refuses it.
     */
    public function supersedes(): bool
    {
        try {
            return GatewayReleaseLayout::fromConfig()->observe($this->ownApplicationPath ?? base_path())->superseded;
        } catch (Throwable) {
            return false;
        }
    }

    public const string Adopted = 'adopted';

    public const string InPlace = 'in_place';

    public const string Unreadable = 'unreadable';

    /** @return array{state: string, commit: ?string} */
    public function read(): array
    {
        try {
            $inventory = GatewayReleaseLayout::fromConfig()->observe();

            return ['state' => $inventory->state, 'commit' => $inventory->current === null ? null : ($inventory->commits[$inventory->current] ?? null)];
        } catch (Throwable) {
            return ['state' => self::Unreadable, 'commit' => null];
        }
    }
}
