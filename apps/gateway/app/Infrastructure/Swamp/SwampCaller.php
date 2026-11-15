<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

/**
 * Who opens a serve connection. Only the two screen reads are views; every other caller has priority, so an Operation start,
 * its copies, the reads that settle a finished run and every release step always connect (docs/reference/swamp-command-steps.md).
 */
enum SwampCaller: string
{
    case Start = 'start';
    case Copy = 'copy';
    case Cancel = 'cancel';
    case Schedule = 'schedule';
    case Subscriber = 'subscriber';
    case Reconcile = 'reconcile';
    case Inspection = 'inspection';
    case Release = 'release';
    case Dependencies = 'dependencies';
    case TaskRuns = 'task_runs';

    /** A read that only refreshes what a screen shows; it yields the window to priority callers. */
    public function isView(): bool
    {
        return $this === self::Dependencies || $this === self::TaskRuns;
    }
}
