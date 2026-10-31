<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsUpdateProjection;

return ['analytics.update' => [
    'workflow' => 'operation-analytics-update-direct',
    'projection' => AnalyticsUpdateProjection::class,
    'private_material' => ['runtime_environment' => 'analytics.runtime_environment'],
]];
