<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Contracts\ProgressReporter;
use App\Support\Cli\LifecycleSummaryRenderer;
use App\Support\Cli\SpinnerTreeRenderer;
use App\Support\Streaming\NullProgressReporter;
use Illuminate\Support\Str;

/**
 * Step-tree rendering pattern: a vertical tree with aligned labels, ANSI cursor
 * updates, and cursor hide/show. Pairs with WithSpinner — add both traits.
 *
 * Step callables return a string: success message, 'fail:...' for failure, or
 * 'skip:...' for warning/skip.
 *
 * Commands handle their own JSON envelopes; this trait owns only the human and
 * SSE-streaming paths.
 *
 * @see .agents/skills/command-designer/SKILL.md
 */
trait WithStepTree
{
    /**
     * @param  list<callable>  $tasks
     * @return list<mixed>
     */
    abstract public function runSequentialWithSpinners(
        array $tasks,
        callable $renderSpinner,
        callable $renderResult,
        ?callable $renderError = null,
    ): array;

    /**
     * Run sequential steps with decorated, non-decorated, and SSE-streaming
     * paths. Requires the WithSpinner trait.
     *
     * Each step can carry an optional `key` — a stable identifier the
     * ProgressReporter uses to address the step in remote streams.
     *
     * @param  list<array{label: string, doneLabel?: string, key?: string, run: callable}>  $steps
     */
    public function runStepTree(
        string $title,
        array $steps,
        string $doneFooter = 'Done',
        string $failFooter = 'Failed',
    ): int {
        $steps = $this->withStepKeys($steps);
        $reporter = app(ProgressReporter::class);
        $streamsProgress = ! $reporter instanceof NullProgressReporter;

        $reporter->tree($title, array_map(static fn (array $s): array => array_filter([
            'key' => $s['key'],
            'label' => $s['label'],
            'doneLabel' => $s['doneLabel'] ?? null,
        ], static fn (mixed $v): bool => $v !== null), $steps));

        if ($streamsProgress) {
            return $this->runStepsForStream($steps, $reporter);
        }

        return $this->runStepsForHuman($title, $steps, $reporter, $doneFooter, $failFooter);
    }

    /**
     * Compute the widest label for alignment.
     *
     * @param  list<array{label: string, doneLabel?: string}>  $steps
     */
    protected function stepTreeLabelWidth(array $steps, string $key = 'doneLabel'): int
    {
        return max(array_map(
            fn (array $s): int => mb_strlen($s[$key] ?? $s['label']),
            $steps,
        ));
    }

    /**
     * Render the initial tree: header, idle placeholders, footer. Hides cursor.
     *
     * @param  list<array{label: string}>  $steps
     */
    protected function renderStepTree(string $title, array $steps, string $footer = 'Working...'): void
    {
        $tree = new SpinnerTreeRenderer($this->output->isDecorated());

        $tree->renderFrame(
            $this->output,
            $title,
            array_map(fn (array $s): string => $s['label'], $steps),
            $footer,
        );
    }

    /**
     * Create a closure that updates step line $i via ANSI cursor movement.
     */
    protected function stepTreeUpdater(int $stepCount): \Closure
    {
        $tree = new SpinnerTreeRenderer($this->output->isDecorated());

        return function (int $i, string $content) use ($tree, $stepCount): void {
            $tree->updateLine($this->output, $i, $stepCount, $content);
        };
    }

    /**
     * Format a spinner frame line with an aligned label.
     */
    protected function stepTreeSpinner(string $frame, string $label, int $labelWidth): string
    {
        return (new LifecycleSummaryRenderer($this->output->isDecorated()))
            ->spinnerLine($frame, $label, $labelWidth);
    }

    protected function stepSuccess(string $label, int $labelWidth, string $message): string
    {
        return (new LifecycleSummaryRenderer($this->output->isDecorated()))
            ->success($label, $labelWidth, $message);
    }

    protected function stepFailed(string $label, int $labelWidth, string $message): string
    {
        return (new LifecycleSummaryRenderer($this->output->isDecorated()))
            ->failure($label, $labelWidth, $message);
    }

    protected function stepSkipped(string $label, int $labelWidth, string $message): string
    {
        return (new LifecycleSummaryRenderer($this->output->isDecorated()))
            ->skipped($label, $labelWidth, $message);
    }

    /**
     * Update the footer and restore cursor.
     */
    protected function finishStepTree(string $message, bool $success = true): void
    {
        $tree = new SpinnerTreeRenderer($this->output->isDecorated());

        $tree->updateFooter(
            $this->output,
            $tree->footerLine($message, $success ? SpinnerTreeRenderer::ACCENT : SpinnerTreeRenderer::RED),
        );

        if ($this->output->isDecorated()) {
            $tree->showCursor($this->output);
        }

        $this->output->writeln('');
    }

    /**
     * @param  list<array{label: string, doneLabel?: string, key: string, run: callable}>  $steps
     */
    private function runStepsForStream(array $steps, ProgressReporter $reporter): int
    {
        $allOk = true;

        foreach ($steps as $step) {
            $reporter->stepStart($step['key']);

            try {
                $message = (string) ($step['run'])();
            } catch (\Throwable $e) {
                $allOk = false;
                $reporter->stepFail($step['key'], $e->getMessage());

                continue;
            }

            if (str_starts_with($message, 'fail:')) {
                $allOk = false;
                $reporter->stepFail($step['key'], substr($message, 5));

                continue;
            }

            if (str_starts_with($message, 'skip:')) {
                $reporter->stepSkip($step['key'], substr($message, 5));

                continue;
            }

            $reporter->stepDone($step['key'], $message === '' ? null : $message);
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array{label: string, doneLabel?: string, key: string, run: callable}>  $steps
     */
    private function runStepsForHuman(
        string $title,
        array $steps,
        ProgressReporter $reporter,
        string $doneFooter,
        string $failFooter,
    ): int {
        $width = $this->stepTreeLabelWidth($steps);
        $this->renderStepTree($title, $steps);
        $updateLine = $this->stepTreeUpdater(count($steps));
        $allOk = true;

        $this->runSequentialWithSpinners(
            array_map(fn (array $s, int $i): callable => function () use ($s, $i, $reporter, $updateLine, $steps, $width): mixed {
                $updateLine(
                    $i,
                    $this->stepTreeSpinner(self::$spinnerFrames[0], $steps[$i]['label'], $width),
                );
                $reporter->stepStart($s['key']);

                return ($s['run'])();
            }, $steps, array_keys($steps)),
            fn (int $i, string $frame) => $updateLine(
                $i,
                $this->stepTreeSpinner($frame, $steps[$i]['label'], $width),
            ),
            function (int $i, mixed $message) use (&$allOk, $updateLine, $steps, $width, $reporter): void {
                $label = $steps[$i]['doneLabel'] ?? $steps[$i]['label'];
                $message = (string) $message;

                if (str_starts_with($message, 'fail:')) {
                    $allOk = false;
                    $reporter->stepFail($steps[$i]['key'], substr($message, 5));
                    $updateLine($i, $this->stepFailed($label, $width, substr($message, 5)));

                    return;
                }

                if (str_starts_with($message, 'skip:')) {
                    $reporter->stepSkip($steps[$i]['key'], substr($message, 5));
                    $updateLine($i, $this->stepSkipped($label, $width, substr($message, 5)));

                    return;
                }

                $reporter->stepDone($steps[$i]['key'], $message === '' ? null : $message);
                $updateLine($i, $this->stepSuccess($label, $width, $message));
            },
            function (int $i, \Throwable $e) use (&$allOk, $updateLine, $steps, $width, $reporter): void {
                $allOk = false;
                $label = $steps[$i]['doneLabel'] ?? $steps[$i]['label'];
                $reporter->stepFail($steps[$i]['key'], $e->getMessage());
                $updateLine($i, $this->stepFailed($label, $width, $e->getMessage()));
            },
        );

        $this->finishStepTree($allOk ? $doneFooter : $failFooter, $allOk);

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Fill in missing `key`s from the label so step schemas stay concise
     * for callers that don't care about addressing.
     *
     * @param  list<array{label: string, doneLabel?: string, key?: string, run: callable}>  $steps
     * @return list<array{label: string, doneLabel?: string, key: string, run: callable}>
     */
    private function withStepKeys(array $steps): array
    {
        $seen = [];

        return array_map(static function (array $step) use (&$seen): array {
            $key = $step['key'] ?? Str::slug($step['label']);

            if ($key === '') {
                $key = 'step';
            }

            $base = $key;
            $i = 1;

            while (isset($seen[$key])) {
                $key = "{$base}-".(++$i);
            }

            $seen[$key] = true;
            $step['key'] = $key;

            return $step;
        }, $steps);
    }
}
