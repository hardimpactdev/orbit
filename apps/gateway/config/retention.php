<?php

declare(strict_types=1);

return [
    'journal_max_mb' => max(1, (int) env('ORBIT_JOURNAL_MAX_MB', 256)),
    'journal_keep_free_mb' => max(1, (int) env('ORBIT_JOURNAL_KEEP_FREE_MB', 1024)),
    'journal_days' => max(1, (int) env('ORBIT_JOURNAL_DAYS', 7)),
    'journal_runtime_max_mb' => max(1, (int) env('ORBIT_JOURNAL_RUNTIME_MAX_MB', 64)),
    'log_rotate_mb' => max(1, (int) env('ORBIT_LOG_ROTATE_MB', 32)),
    'log_rotate_keep' => max(1, (int) env('ORBIT_LOG_ROTATE_KEEP', 7)),
    'failed_jobs_hours' => max(1, (int) env('ORBIT_FAILED_JOBS_HOURS', 168)),
    'job_batch_hours' => max(1, (int) env('ORBIT_JOB_BATCH_HOURS', 24)),
];
