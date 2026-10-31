<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Operations\DirectOperationContribution;

final readonly class AiRouterDirectOperations implements DirectOperationContribution
{
    public function definitions(): array
    {
        return ['ai_router.account_update' => [
            'workflow' => 'operation-ai-router-account-update',
            'projection' => AiRouterAccountProjection::class,
            'private_material' => ['router' => 'ai_router.management'],
        ]];
    }
}
