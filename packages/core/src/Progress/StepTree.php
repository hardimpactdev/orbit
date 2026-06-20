<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Renders the documented animated step tree
 * (apps/docs/content/ux/commands/progress/progress-tree.md).
 *
 * Two execution shapes are supported:
 *
 * - {@see self::run()} — sequential client-side phases. Each step's `run`
 *   closure executes in order; only the active row animates, then it settles
 *   before the next step starts. Use when the command genuinely performs the
 *   phases itself (local resolution, then a write, then a refresh).
 * - {@see self::runOperation()} — a single atomic operation (typically one
 *   gateway call) whose documented phases all settle together from the
 *   outcome. Every phase row animates while the work runs, then all rows settle
 *   green on success. On failure no row is falsely marked done — only the
 *   footer turns red. Use for atomic gateway mutations so the tree never claims
 *   a phase completed when the call ultimately failed.
 *
 * Animation while a blocking closure runs is driven by a forked ticker process
 * that repaints the active rows every frame interval. When the output is not
 * decorated (tests, piped output) no process is forked and only settled rows
 * are written, keeping output deterministic.
 *
 * @phpstan-type StepDefinition array{label: string, doneLabel?: string, run: callable(): mixed}
 * @phpstan-type PhaseDefinition array{label: string, doneLabel?: string}
 */
final class StepTree
{
    private const int DEFAULT_FRAME_INTERVAL_US = 300_000;

    private readonly SpinnerTreeRenderer $tree;

    private readonly LifecycleSummaryRenderer $summary;

    private readonly bool $decorated;

    private readonly int $frameIntervalUs;

    private int $labelWidth = 0;

    private int $frame = 0;

    private ?int $tickerPid = null;

    public function __construct(
        private readonly OutputInterface $output,
        ?int $frameIntervalUs = null,
    ) {
        $this->decorated = $output->isDecorated();
        $this->frameIntervalUs = max(0, $frameIntervalUs ?? self::DEFAULT_FRAME_INTERVAL_US);
        $this->tree = new SpinnerTreeRenderer($this->decorated);
        $this->summary = new LifecycleSummaryRenderer($this->decorated);
    }

    /**
     * Render the tree and run each step's closure in order.
     *
     * The success footer may be a string, or a closure resolved after every
     * step completes (so it can reflect values captured during the run).
     *
     * @param  list<StepDefinition>  $steps
     * @param  string|Closure(): string  $doneFooter
     */
    public function run(string $title, array $steps, string|Closure $doneFooter, ?string $failFooter = null): StepTreeResult
    {
        [$labels, $doneLabels] = $this->prepare($title, $steps);
        $total = count($steps);
        $results = [];

        foreach (array_values($steps) as $index => $step) {
            $this->startSpinner([$index], $total, $labels);

            try {
                /** @var callable(): mixed $run */
                $run = $step['run'];
                $result = $run();
            } catch (Throwable $exception) {
                $this->stopSpinner();
                $this->tree->updateLine(
                    $this->output,
                    $index,
                    $total,
                    $this->summary->failure($doneLabels[$index], $this->labelWidth, $this->throwableMessage($exception)),
                );
                $this->finishFooter($failFooter ?? $this->throwableMessage($exception), success: false);

                return StepTreeResult::failed($exception, $results);
            }

            $this->stopSpinner();
            $this->tree->updateLine(
                $this->output,
                $index,
                $total,
                $this->summary->success($doneLabels[$index], $this->labelWidth, is_string($result) ? $result : ''),
            );
            $results[] = $result;
        }

        $this->finishFooter($this->resolveFooter($doneFooter), success: true);

        return StepTreeResult::completed($results);
    }

    /**
     * Render the documented phases and run a single atomic operation.
     *
     * Every phase row animates while `$work` runs. On success all rows settle
     * green together and the success footer is shown. On failure no row is
     * marked done; only the footer turns red with the failure message.
     *
     * @param  list<PhaseDefinition>  $phases
     * @param  Closure(): mixed  $work
     * @param  string|Closure(): string  $doneFooter
     */
    public function runOperation(string $title, array $phases, Closure $work, string|Closure $doneFooter, ?string $failFooter = null): StepTreeResult
    {
        [$labels, $doneLabels] = $this->prepare($title, $phases);
        $total = count($phases);
        $indices = $total > 0 ? range(0, $total - 1) : [];

        $this->startSpinner($indices, $total, $labels);

        try {
            $result = $work();
        } catch (Throwable $exception) {
            $this->stopSpinner();
            $this->finishFooter($failFooter ?? $this->throwableMessage($exception), success: false);

            return StepTreeResult::failed($exception, []);
        }

        $this->stopSpinner();

        foreach ($indices as $index) {
            $this->tree->updateLine(
                $this->output,
                $index,
                $total,
                $this->summary->success($doneLabels[$index], $this->labelWidth, ''),
            );
        }

        $this->finishFooter($this->resolveFooter($doneFooter), success: true);

        return StepTreeResult::completed([$result]);
    }

    /**
     * @param  list<array{label: string, doneLabel?: string, run?: callable(): mixed}>  $steps
     * @return array{0: list<string>, 1: list<string>}
     */
    private function prepare(string $title, array $steps): array
    {
        $labels = array_map(static fn (array $step): string => (string) ($step['label'] ?? ''), $steps);
        $doneLabels = array_map(
            static fn (array $step): string => (string) ($step['doneLabel'] ?? $step['label'] ?? ''),
            $steps,
        );

        $this->labelWidth = $labels === []
            ? 0
            : max(array_map(mb_strlen(...), [...$labels, ...$doneLabels]));

        $this->tree->renderFrame(
            $this->output,
            $title,
            array_map(fn (string $label): string => str_pad($label, $this->labelWidth), $labels),
            'Working...',
        );

        return [array_values($labels), array_values($doneLabels)];
    }

    /**
     * @param  list<int>  $indices
     * @param  list<string>  $labels
     */
    private function startSpinner(array $indices, int $total, array $labels): void
    {
        if (! $this->decorated || $indices === []) {
            return;
        }

        $this->frame = 0;
        $this->paintActive($indices, $total, $labels);
        $this->forkTicker($indices, $total, $labels);
    }

    /**
     * @param  list<int>  $indices
     * @param  list<string>  $labels
     */
    private function paintActive(array $indices, int $total, array $labels): void
    {
        $frames = SpinnerTreeRenderer::spinnerFrames();
        $frame = $frames[$this->frame % count($frames)];

        foreach ($indices as $index) {
            $this->tree->updateLine(
                $this->output,
                $index,
                $total,
                $this->summary->spinnerLine($frame, $labels[$index], $this->labelWidth),
            );
        }

        $this->frame++;
    }

    /**
     * @param  list<int>  $indices
     * @param  list<string>  $labels
     */
    private function forkTicker(array $indices, int $total, array $labels): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            return;
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            return;
        }

        if ($pid === 0) {
            if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
                pcntl_signal(SIGTERM, static function (): void {
                    exit(0);
                });
            }

            while (true) {
                if (posix_getppid() === 1) {
                    exit(0);
                }

                usleep($this->frameIntervalUs);
                $this->paintActive($indices, $total, $labels);
            }
        }

        $this->tickerPid = $pid;
    }

    private function stopSpinner(): void
    {
        if ($this->tickerPid === null || ! function_exists('posix_kill')) {
            $this->tickerPid = null;

            return;
        }

        posix_kill($this->tickerPid, SIGTERM);

        if (function_exists('pcntl_waitpid')) {
            pcntl_waitpid($this->tickerPid, $status);
        }

        $this->tickerPid = null;
    }

    /**
     * @param  string|Closure(): string  $footer
     */
    private function resolveFooter(string|Closure $footer): string
    {
        return $footer instanceof Closure ? $footer() : $footer;
    }

    private function finishFooter(string $footer, bool $success): void
    {
        $color = $success ? SpinnerTreeRenderer::ACCENT : SpinnerTreeRenderer::RED;
        $this->tree->updateFooter($this->output, $this->tree->footerLine($footer, $color));

        if ($this->decorated) {
            $this->tree->showCursor($this->output);
            $this->output->writeln('');
        }
    }

    private function throwableMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message === '' ? 'failed' : $message;
    }

    public function __destruct()
    {
        $this->stopSpinner();
    }
}
