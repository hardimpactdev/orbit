<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsPublishProjection;

return [
    'dns.publish' => [
        'workflow' => 'dns-publish',
        'projection' => DnsPublishProjection::class,
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'dns', 'step' => 'Check the answers', 'native' => true],
    ],
];
