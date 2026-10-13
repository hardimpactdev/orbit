<?php

declare(strict_types=1);

return [
    'poll_interval' => 5000,
    'job_navigation_breakdown' => false,
    'job_payload_allowed_classes' => [],
    // Bulk actions run on the Gateway queue, which Horizon works (docs/reference/gateway-queue.md).
    'bulk_operations' => [
        'connection' => 'gateway',
        'queue' => 'tasks',
    ],
];
