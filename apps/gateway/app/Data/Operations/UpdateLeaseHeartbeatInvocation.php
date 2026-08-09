<?php

declare(strict_types=1);

namespace App\Data\Operations;

final readonly class UpdateLeaseHeartbeatInvocation
{
    /**
     * @param  positive-int  $parentPid
     * @param  positive-int  $ttlSeconds
     * @param  positive-int  $intervalSeconds
     */
    public function __construct(
        public UpdateLeaseHeartbeatIdentity $identity,
        public int $parentPid,
        public int $ttlSeconds,
        public int $intervalSeconds,
        public bool $once,
    ) {}
}
