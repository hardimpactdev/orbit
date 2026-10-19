<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Data;

use SensitiveParameter;

final readonly class EnableAiRouterData
{
    public function __construct(
        public int $nodeId,
        public string $cacheConnection,
        public string $routerUrl,
        #[SensitiveParameter]
        public string $managementKey,
    ) {}
}
