<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Event-driven progress tree for a stream of external step events (gateway SSE
 * progress frames, or remote SSH progress relayed frame-by-frame).
 *
 * Unlike {@see StepTree}, the caller does not own the work — it feeds discrete
 * events as they arrive: {@see self::tree()} to paint the idle tree, then
 * {@see self::step()} with `start`/`done`/`fail`/`skip`/progress statuses, and
 * {@see self::finish()} for the footer. The active row animates between events
 * via a ticker, so the operator sees motion while the main process blocks reading the
 * next frame.
 */
final class StreamedStepTree
{
    private ?SpinnerTreeRenderer $tree = null;

    private ?LifecycleSummaryRenderer $summary = null;

    /** @var list<array{key: string, label: string, doneLabel: ?string}> */
    private array $steps = [];

    /** @var array<string, int> */
    private array $indexByKey = [];

    private int $labelWidth = 0;

    private ?string $activeKey = null;

    private string $activeMessage = '';

    private int $frame = 0;

    private int $lastFrameAtUs = 0;

    private ?ForkedFrameTicker $ticker = null;

    private ?StreamedStepProcessTicker $processTicker = null;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly int $frameIntervalUs = ForkedFrameTicker::DEFAULT_INTERVAL_US,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    public function tree(string $title, array $steps): void
    {
        $this->steps = array_values(array_map(static fn (array $step): array => [
            'key' => (string) ($step['key'] ?? ''),
            'label' => (string) ($step['label'] ?? ''),
            'doneLabel' => isset($step['doneLabel']) && is_string($step['doneLabel'])
                ? $step['doneLabel']
                : null,
        ], $steps));
        $this->indexByKey = [];

        foreach ($this->steps as $index => $step) {
            $this->indexByKey[$step['key']] = $index;
        }

        $this->labelWidth = $this->computeLabelWidth($this->steps);
        $styled = LiveRepaintOutput::supports($this->output);
        $this->tree = new SpinnerTreeRenderer($styled);
        $this->summary = new LifecycleSummaryRenderer($styled);

        $this->tree->renderFrame(
            $this->output,
            $title,
            array_map(fn (array $step): string => str_pad($step['label'], $this->labelWidth), $this->steps),
            'Working...',
        );
    }

    public function step(string $key, string $status, ?string $message = null): void
    {
        if ($key === '' || $this->tree === null || $this->summary === null) {
            return;
        }

        $index = $this->indexByKey[$key] ?? null;

        if ($index === null) {
            return;
        }

        $step = $this->steps[$index];
        $doneLabel = $step['doneLabel'] ?? $step['label'];

        match ($status) {
            'start' => $this->startStep($key),
            'done' => $this->completeStep(
                $index,
                $key,
                $this->summary->success($doneLabel, $this->labelWidth, $message ?? ''),
            ),
            'fail' => $this->completeStep(
                $index,
                $key,
                $this->summary->failure($doneLabel, $this->labelWidth, $message ?? 'failed'),
            ),
            'skip' => $this->completeStep(
                $index,
                $key,
                $this->summary->skipped($doneLabel, $this->labelWidth, $message ?? 'skipped'),
            ),
            default => $this->progressStep($index, $key, $message ?? $status),
        };
    }

    public function tick(): void
    {
        if ($this->activeKey === null || $this->tree === null || $this->summary === null) {
            return;
        }

        $nowUs = (int) (microtime(true) * 1_000_000);

        if ($this->lastFrameAtUs !== 0 && ($nowUs - $this->lastFrameAtUs) < $this->frameIntervalUs) {
            return;
        }

        $index = $this->indexByKey[$this->activeKey] ?? null;

        if ($index === null) {
            return;
        }

        $this->lastFrameAtUs = $nowUs;

        $step = $this->steps[$index];
        $frames = SpinnerTreeRenderer::spinnerFrames();
        $this->writeLine(
            $index,
            $this->summary->spinnerLine(
                $frames[$this->frame % count($frames)],
                $step['label'],
                $this->labelWidth,
                $this->activeMessage,
            ),
        );
        $this->frame++;
    }

    public function finish(string $footer, bool $success = true): void
    {
        $tree = $this->tree;

        if ($tree === null) {
            return;
        }

        $this->stopSpinnerProcess();

        $color = $success ? SpinnerTreeRenderer::ACCENT : SpinnerTreeRenderer::RED;
        $tree->updateFooter($this->output, $tree->footerLine($footer, $color));

        if ($this->output->isDecorated()) {
            $tree->showCursor($this->output);
        }

        $this->output->writeln('');
    }

    public function isStarted(): bool
    {
        return $this->tree !== null;
    }

    private function progressStep(int $index, string $key, string $message): void
    {
        $this->stopSpinnerProcess();
        $this->activeMessage = $message;

        if ($this->activeKey !== $key) {
            $this->activeKey = $key;
            $this->frame = 0;
        }

        $step = $this->steps[$index];
        $summary = $this->summary;

        if ($summary === null) {
            return;
        }

        $frames = SpinnerTreeRenderer::spinnerFrames();
        $this->writeLine(
            $index,
            $summary->spinnerLine(
                $frames[$this->frame % count($frames)],
                $step['label'],
                $this->labelWidth,
                $message,
            ),
        );
        $this->lastFrameAtUs = (int) (microtime(true) * 1_000_000);
        $this->frame++;
        $this->startSpinnerProcess();
    }

    private function startStep(string $key): void
    {
        $this->stopSpinnerProcess();

        $this->activeKey = $key;
        $this->activeMessage = '';
        $this->frame = 0;
        $this->lastFrameAtUs = 0;
        $this->tick();
        $this->startSpinnerProcess();
    }

    private function completeStep(int $index, string $key, string $content): void
    {
        if ($this->activeKey === $key) {
            $this->activeKey = null;
            $this->activeMessage = '';
            $this->stopSpinnerProcess();
        }

        $this->writeLine($index, $content);
    }

    private function writeLine(int $index, string $content): void
    {
        $this->tree?->updateLine($this->output, $index, count($this->steps), $content);
    }

    private function startSpinnerProcess(): void
    {
        if (! LiveRepaintOutput::supports($this->output)) {
            return;
        }

        if ($this->ticker !== null || $this->processTicker !== null) {
            return;
        }

        if ($this->shouldUseProcessTicker()) {
            $ticker = new StreamedStepProcessTicker;

            if ($ticker->start($this->processTickerFrames(), $this->frameIntervalUs)) {
                $this->processTicker = $ticker;

                return;
            }
        }

        $this->ticker = new ForkedFrameTicker($this->frameIntervalUs);
        $this->ticker->start(function (): void {
            $this->tick();
        });
    }

    private function stopSpinnerProcess(): void
    {
        $this->processTicker?->stop();
        $this->processTicker = null;

        $this->ticker?->stop();
        $this->ticker = null;
    }

    public function __destruct()
    {
        $this->stopSpinnerProcess();
    }

    /**
     * @param  list<array{key: string, label: string, doneLabel: ?string}>  $steps
     */
    private function computeLabelWidth(array $steps): int
    {
        if ($steps === []) {
            return 0;
        }

        return max(array_map(
            static fn (array $step): int => mb_strlen($step['doneLabel'] ?? $step['label']),
            $steps,
        ));
    }

    private function shouldUseProcessTicker(): bool
    {
        $stream = LiveRepaintOutput::resolveStream($this->output);

        return is_resource($stream) && function_exists('stream_isatty') && stream_isatty($stream);
    }

    /**
     * @return list<string>
     */
    private function processTickerFrames(): array
    {
        $tree = $this->tree;
        $summary = $this->summary;

        if ($this->activeKey === null || $tree === null || $summary === null) {
            return [];
        }

        $index = $this->indexByKey[$this->activeKey] ?? null;

        if ($index === null) {
            return [];
        }

        $step = $this->steps[$index];
        $spinnerFrames = SpinnerTreeRenderer::spinnerFrames();

        return array_map(
            fn (int $offset): string => $tree->updateLineSequence(
                $index,
                count($this->steps),
                $summary->spinnerLine(
                    $spinnerFrames[($this->frame + $offset) % count($spinnerFrames)],
                    $step['label'],
                    $this->labelWidth,
                    $this->activeMessage,
                ),
            ),
            array_keys($spinnerFrames),
        );
    }
}
