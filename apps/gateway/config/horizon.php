<?php

declare(strict_types=1);

/*
 * Laravel Horizon works the Gateway queue (docs/reference/gateway-queue.md): separate supervisors keep short jobs
 * independent of builds. Builds have a separate pool. It runs as the Gateway Node's `horizon` Process.
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
        'gateway:builds' => 3600,
        'gateway:operations' => 300,
        'gateway:operations-prompt' => 60,
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
        'builds' => [
            'connection' => 'gateway',
            'queue' => ['builds'],
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
        // Jobs that record a Swamp run in its Operation (docs/reference/operations.md). One worker: the Gateway's memory is
        // better spent on PHP-FPM, and a second job waits in the queue until the first ends. Route promotions keep this one worker
        // and its order; run starts and the projection of a run that just ended have their own workers below.
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
        // Native run starts (StartAdmittedSwampRun), four side by side, one per CPU of the Swamp Node. A start that waits up to its
        // 120-second confirmation holds one of them, so other starts go on. Each start runs under its Operation's row lock and the
        // target hold; the job timeout stays 150 seconds, below the longest one, so the Horizon unit's drain does not change.
        'operation-starts' => [
            'connection' => 'gateway',
            'queue' => ['operation-starts'],
            'balance' => false,
            'minProcesses' => 4,
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 150,
            'nice' => 0,
        ],
        // The projection of one Operation whose native run ended (ProjectEndedNativeRun), at once: a minute pass that runs on the
        // `operations` worker does not hold it back. One worker, the same 256 MB cap. Its job timeout stays below the longest one,
        // so the Horizon unit's drain does not change.
        'operations-prompt' => [
            'connection' => 'gateway',
            'queue' => ['operations-prompt'],
            'balance' => false,
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 150,
            'nice' => 0,
        ],
        // SPIKE (Durable Workflow): workflow tasks only replay history and schedule the next step, so they are short.
        'dw-workflow' => [
            'connection' => 'gateway',
            'queue' => ['dw-workflow'],
            'balance' => false,
            'minProcesses' => 4,
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 0,
            'timeout' => 60,
            'nice' => 0,
        ],
        // SPIKE (Durable Workflow): activities run Ansible against Nodes and may take minutes.
        'dw-activity' => [
            'connection' => 'gateway',
            'queue' => ['dw-activity'],
            'balance' => false,
            'minProcesses' => 4,
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 0,
            'timeout' => 900,
            'nice' => 0,
        ],
        // SPIKE: plain jobs on idempotent Executor primitives, on the short-retry connection.
        'spike-ops' => [
            'connection' => 'spike-ops',
            'queue' => ['spike-ops'],
            'balance' => false,
            'minProcesses' => 4,
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 0,
            'timeout' => 120,
            'nice' => 0,
        ],
    ],
    // The same supervisors in every environment.
    'environments' => [
        '*' => [
            'tasks' => [],
            'builds' => [],
            'operations' => [],
            'operation-starts' => [],
            'operations-prompt' => [],
            'dw-workflow' => [],
            'dw-activity' => [],
            'spike-ops' => [],
        ],
    ],
    'watch' => [],
];
