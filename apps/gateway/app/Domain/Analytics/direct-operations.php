<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsIntentProjection;
use App\Domain\Analytics\AnalyticsSigningPrimitive;

return ['analytics.disable' => ['workflow' => 'operation-analytics-disable-direct', 'writer_config' => 'swamp.direct_writers', 'writer_state' => 'analytics_writer', 'projection' => AnalyticsIntentProjection::class],
    'analytics.enable' => [
        'workflow' => 'operation-analytics-enable-direct', 'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'analytics_writer', 'projection' => AnalyticsIntentProjection::class,
        'primitive' => AnalyticsSigningPrimitive::class,
        'private_material' => ['plausible_credentials' => 'analytics.plausible_credentials'],
    ]];
