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
        // The end check is the tracking host's TLS probe; a private host ends at its publish step.
        'terminal_result' => ['job' => 'analytics', 'step' => 'Probe the tracking host-0', 'alternatives' => ['Publish Caddy, reload-0'], 'native' => true],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^analytics-plausible-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$']],
    ],
    'analytics.disable' => [
        'workflow' => 'operation-analytics-disable-direct',
        'projection' => AnalyticsDisableProjection::class,
        'workflow_inputs' => true,
        // The end check is the first Node's cfgmgmt check that the tracking hosts are gone.
        'terminal_result' => ['job' => 'analytics', 'step' => 'Check the hosts are gone-0', 'native' => true],
    ],
    'analytics.update' => [
        'workflow' => 'operation-analytics-update-direct',
        'projection' => AnalyticsUpdateProjection::class,
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'update', 'step' => 'result'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-create-operation-[1-9][0-9]*$']],
    ],
];
