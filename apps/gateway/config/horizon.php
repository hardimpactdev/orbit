<?php

declare(strict_types=1);

/*
 * Laravel Horizon works the Gateway queue (docs/reference/gateway-queue.md): one supervisor per queue, so a long build
 * never holds up a short job. The `orbit-horizon.service` unit starts only while this file exists in the current
 * release, so a switch back to a release without Horizon leaves it stopped.
 */
return [
    'name' => 'orbit-gateway',
    'domain' => null,
    'path' => 'horizon',
    'use' => 'gateway',
    'prefix' => 'orbit-gateway-horizon:',
    // The dashboard is off: GatewayQueueServiceProvider denies every request.
    'middleware' => ['web'],
    'waits' => [
        'gateway:tasks' => 60,
        'gateway:builds' => 3600,
    ],
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'silenced' => [],
    'silenced_tags' => [],
    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],
    // A release terminates the master at once. Each running job finishes in its old worker process, and the restarted
    // master starts new workers on the new release right away.
    'fast_termination' => true,
    'memory_limit' => 256,
    'defaults' => [
        'tasks' => [
            'connection' => 'gateway',
            'queue' => ['tasks'],
            'balance' => false,
            'minProcesses' => 3,
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 1700,
            'nice' => 0,
        ],
        'builds' => [
            'connection' => 'gateway',
            'queue' => ['builds'],
            'balance' => false,
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 1700,
            'nice' => 0,
        ],
    ],
    // The same supervisors in every environment.
    'environments' => [
        '*' => [
            'tasks' => [],
            'builds' => [],
        ],
    ],
    'watch' => [],
];
