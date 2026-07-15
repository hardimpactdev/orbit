<?php

declare(strict_types=1);

namespace App\Services\Node;

use RuntimeException;

final class NodeBootstrapUnsupportedPlatform extends RuntimeException
{
    public function __construct(
        public readonly string $platform,
        public readonly string $architecture,
    ) {
        parent::__construct(
            "Target platform [{$platform}/{$architecture}] is not supported for workload bootstrap.",
        );
    }
}
