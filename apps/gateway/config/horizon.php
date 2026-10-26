<?php

declare(strict_types=1);

/*
 * Laravel Horizon works the Gateway queue (docs/reference/gateway-queue.md): separate supervisors keep short jobs
 * independent of builds. Required and optional builds share an ordered pool. It runs as the Gateway Node's `horizon` Process.
 */
return [
    'name' => 'orbit-gateway',
    'domain' => null,
    'path' => 'horizon',
    'use' => 'gateway',
    'prefix' => 'orbit-gateway-horizon:',
    // The dashboard admits WireGuard peers with Gateway access (GatewayQueueServiceProvider). The web group's request
    // forgery check runs origin-only (bootstrap/app.php), because the dashboard has no CSRF token.
    'middleware' => ['web'],
    'waits' => [
        'gateway:tasks' => 60,
        'gateway:problems' => 600,
        'gateway:task-vms' => 600,
        'gateway:builds' => 3600,
        'gateway:builds-optional' => 3600,
        'gateway:operations' => 300,
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
    // Release switching drains the complete Horizon control group before publication. The master waits for its
    // running jobs; the serving handoff then starts the stopped Process on the selected release.
    'fast_termination' => false,
    'memory_limit' => 256,
    'defaults' => [
        'tasks' => [
            'connection' => 'gateway',
            'queue' => ['tasks', 'problems'],
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
        // One worker, so task VM jobs run one at a time and in order. The timeout is the longest task VM job, EnrollTaskVm;
        // no task-vms job may exceed it.
        'task-vms' => [
            'connection' => 'gateway',
            'queue' => ['task-vms'],
            'balance' => false,
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 1500,
            'nice' => 0,
        ],
        'builds' => [
            'connection' => 'gateway',
            'queue' => ['builds', 'builds-optional'],
            'balance' => false,
            'minProcesses' => 2,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 1700,
            'nice' => 0,
        ],
        // Operations, such as an Instance deploy (docs/reference/operations.md). One worker: an operation holds its target
        // anyway, a single operator rarely deploys two targets at once, and the Gateway's memory is better spent on PHP-FPM.
        // A second operation waits in the queue until the first ends. The timeout stays above the deadline of 1600 seconds.
        'operations' => [
            'connection' => 'gateway',
            'queue' => ['operations'],
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
            'task-vms' => [],
            'builds' => [],
            'operations' => [],
        ],
    ],
    'watch' => [],
];
