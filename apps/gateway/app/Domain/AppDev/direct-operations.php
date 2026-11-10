<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsPublishProjection;

return [
    // The phase prints its result.json in the terminal step; the shared reader parses that stdout (no `native`), so a failed run keeps its code.
    'dns.publish' => [
        'workflow' => 'dns-publish',
        'projection' => DnsPublishProjection::class,
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'publish', 'step' => 'Report the result'],
    ],
];
