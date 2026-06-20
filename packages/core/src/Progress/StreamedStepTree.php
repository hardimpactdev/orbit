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
 * via a forked ticker, so the operator sees motion while the main process
 * blocks reading the next frame.
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

    private ?ForkedFrameTicker $ticker = null;

    public function __construct(
        private readonly OutputInterface $output,
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
        $this->tree = new SpinnerTreeRenderer($this->output->isDecorated());
        $this->summary = new LifecycleSummaryRenderer($this->output->isDecorated());

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
            'done' => $this->completeStep($index, $key, $this->summary->success($doneLabel, $this->labelWidth, (string) ($message ?? ''))),
            'fail' => $this->completeStep($index, $key, $this->summary->failure($doneLabel, $this->labelWidth, (string) ($message ?? 'failed'))),
            'skip' => $this->completeStep($index, $key, $this->summary->skipped($doneLabel, $this->labelWidth, (string) ($message ?? 'skipped'))),
            default => $this->progressStep($index, $key, (string) ($message ?? $status)),
        };
    }

    public function tick(): void
    {
        if ($this->activeKey === null || $this->tree === null || $this->summary === null) {
            return;
        }

        $index = $this->indexByKey[$this->activeKey] ?? null;

        if ($index === null) {
            return;
        }

        $step = $this->steps[$index];
        $frames = SpinnerTreeRenderer::spinnerFrames();
        $this->writeLine(
            $index,
            $this->summary->spinnerLine($frames[$this->frame % count($frames)], $step['label'], $this->labelWidth, $this->activeMessage),
        );
        $this->frame++;
    }

    public function finish(string $footer, bool $success = true): void
    {
        if ($this->tree === null) {
            return;
        }

        $this->stopSpinnerProcess();

        $color = $success ? SpinnerTreeRenderer::ACCENT : SpinnerTreeRenderer::RED;
        $this->tree->updateFooter($this->output, $this->tree->footerLine($footer, $color));

        if ($this->output->isDecorated()) {
            $this->tree->showCursor($this->output);
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
        $frames = SpinnerTreeRenderer::spinnerFrames();
        $this->writeLine(
            $index,
            $this->summary->spinnerLine($frames[$this->frame % count($frames)], $step['label'], $this->labelWidth, $message),
        );
        $this->frame++;
        $this->startSpinnerProcess();
    }

    private function startStep(string $key): void
    {
        $this->stopSpinnerProcess();

        $this->activeKey = $key;
        $this->activeMessage = '';
        $this->frame = 0;
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
        if (! $this->output->isDecorated()) {
            return;
        }

        $this->ticker ??= new ForkedFrameTicker;
        $this->ticker->start(function (): void {
            $this->tick();
        });
    }

    private function stopSpinnerProcess(): void
    {
        $this->ticker?->stop();
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
}
