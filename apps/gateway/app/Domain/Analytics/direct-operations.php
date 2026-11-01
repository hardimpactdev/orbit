<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsDisableProjection;
use App\Domain\Analytics\AnalyticsIntentProjection;
use App\Domain\Analytics\AnalyticsUpdateProjection;

return [
    'analytics.enable' => [
        'workflow' => 'operation-analytics-enable-direct',
        'projection' => AnalyticsIntentProjection::class,
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'finish', 'step' => 'result'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^analytics-plausible-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$']],
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
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'update', 'step' => 'result'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-create-operation-[1-9][0-9]*$']],
    ],
];
