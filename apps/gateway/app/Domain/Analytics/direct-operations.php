<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsUpdateProjection;

return ['analytics.update' => [
    'workflow' => 'operation-analytics-update-direct',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'analytics_update_writer',
    'projection' => AnalyticsUpdateProjection::class,
    'private_material' => ['runtime_environment' => 'analytics.runtime_environment'],
]];
