<?php

declare(strict_types=1);

namespace App\Data\Operations;

use SensitiveParameter;

final readonly class UpdateLeaseHeartbeatIdentity
{
    public function __construct(
        public string $operationRunId,
        public int $fleetLeaseId,
        #[SensitiveParameter]
        public string $ownerToken,
    ) {}
}
