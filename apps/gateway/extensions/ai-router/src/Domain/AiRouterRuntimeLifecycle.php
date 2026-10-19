<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Models\Node;
use SensitiveParameter;

interface AiRouterRuntimeLifecycle
{
    /**
     * @param  array<string, string>  $environment
     */
    public function converge(
        Node $node,
        #[SensitiveParameter]
        array $environment,
    ): void;

    public function remove(Node $node): void;
}
