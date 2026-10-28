<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;

final readonly class AiRouterCacheRenameCommand implements OperationGatewayCommand
{
    public function __construct(private AiRouterCache $cache) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
    }

    public function run(Operation $operation, array $input): array
    {
        $this->cache->renameCollectorKeys('orbit:proxycli:', 'orbit:ai-router:');

        return ['changed' => true];
    }
}
