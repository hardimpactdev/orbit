<?php

declare(strict_types=1);

namespace App\Services\AgentIde;

use App\Contracts\OpenCodeClientFactory;
use App\Models\Project;
use HardImpact\OpenCode\OpenCode;

final readonly class SdkOpenCodeClientFactory implements OpenCodeClientFactory
{
    public function __construct(
        private OpenCodeServerConfigResolver $configResolver,
    ) {}

    public function forApp(Project $app): OpenCode
    {
        $config = $this->configResolver->resolve($app);

        return new OpenCode($config->url, $config->username, $config->password);
    }
}
