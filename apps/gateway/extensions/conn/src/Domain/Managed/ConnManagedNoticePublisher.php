<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Managed;

/** Publishes one restrictive `conn.authority.revoked` notice on one target channel. False when it did not reach Reverb. */
interface ConnManagedNoticePublisher
{
    /** @param array{environment_id: string, generation: int, revision: int, edge_ids: list<int>} $data */
    public function publish(string $channel, array $data): bool;
}
