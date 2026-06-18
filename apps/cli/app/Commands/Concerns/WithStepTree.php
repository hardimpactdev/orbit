<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use Closure;
use Illuminate\Console\Command;
use Orbit\Core\Progress\StepTree;
use Orbit\Core\Progress\StepTreeResult;

/**
 * Canonical entry point for the animated progress tree in human-rendered
 * commands. Drives the shared {@see StepTree} engine with the command's
 * console output, so commands declare named steps with `run` closures and the
 * renderer owns the idle/active/settled lifecycle and footer.
 *
 * See apps/docs/content/ux/commands/progress/progress-tree.md.
 *
 * @mixin Command
 */
trait WithStepTree
{
    /**
     * Render an animated step tree, executing each step's `run` closure in order.
     *
     * @param  list<array{label: string, doneLabel?: string, run: callable(): mixed}>  $steps
     * @param  string|Closure(): string  $doneFooter
     */
    protected function runStepTree(string $title, array $steps, string|Closure $doneFooter, ?string $failFooter = null): StepTreeResult
    {
        return new StepTree($this->output)->run($title, $steps, $doneFooter, $failFooter);
    }

    /**
     * Render the documented phases for a single atomic operation (typically one
     * gateway call). Every phase row animates while `$work` runs and settles
     * together on success; on failure no phase is marked done.
     *
     * @param  list<array{label: string, doneLabel?: string}>  $phases
     * @param  Closure(): mixed  $work
     * @param  string|Closure(): string  $doneFooter
     */
    protected function runStepOperation(string $title, array $phases, Closure $work, string|Closure $doneFooter, ?string $failFooter = null): StepTreeResult
    {
        return new StepTree($this->output)->runOperation($title, $phases, $work, $doneFooter, $failFooter);
    }
}
