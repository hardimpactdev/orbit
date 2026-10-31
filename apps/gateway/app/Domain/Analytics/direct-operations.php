<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsDisableProjection;
use App\Domain\Analytics\AnalyticsIntentProjection;
use App\Domain\Analytics\AnalyticsUpdateProjection;

return [
    'analytics.enable' => [
        'workflow' => 'operation-analytics-enable-direct',
        'projection' => AnalyticsIntentProjection::class,
        'private_material' => ['plausible_credentials' => 'analytics.plausible_credentials'],
    ],
    'analytics.disable' => [
        'workflow' => 'operation-analytics-disable-direct',
        'projection' => AnalyticsDisableProjection::class,
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'finish', 'step' => 'result'],
    ],
    'analytics.update' => [
        'workflow' => 'operation-analytics-update-direct',
        'projection' => AnalyticsUpdateProjection::class,
        'private_material' => ['runtime_environment' => 'analytics.runtime_environment'],
    ],
];
