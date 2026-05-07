<?php

declare(strict_types=1);

namespace App\Support\Cli;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders the per-target progress tree for `update:all`.
 *
 * Owns: tree layout, per-row label updates as each target moves through
 * `Pulling source` → `Installing dependencies` → `Running migrations` → `Done`,
 * and dynamic extension when remote targets are discovered mid-flight.
 *
 * Each target is one row in the tree. The active row uses cyan `◉` with the
 * present-participle stage label; completed rows use green `●` with `Done`;
 * failed rows use red `●` with `Failed`.
 */
final class UpdateAllProgress
{
    private const string STATE_PENDING = 'pending';

    private const string STATE_ACTIVE = 'active';

    private const string STATE_DONE = 'done';

    private const string STATE_FAILED = 'failed';

    private const string TITLE = 'Updating Orbit nodes';

    private SpinnerTreeRenderer $tree;

    private LifecycleSummaryRenderer $summary;

    /** @var list<string> Ordered target keys */
    private array $order = [];

    /** @var array<string, array{label: string, state: string, message: string}> */
    private array $rows = [];

    private int $labelWidth = 0;

    private bool $finished = false;

    private bool $rendered = false;

    /**
     * @param  list<array{target: string, node: string|null, role: string|null}>  $initialTargets
     */
    public function __construct(
        private readonly OutputInterface $output,
        array $initialTargets,
    ) {
        $this->tree = new SpinnerTreeRenderer($output->isDecorated());
        $this->summary = new LifecycleSummaryRenderer($output->isDecorated());

        foreach ($initialTargets as $target) {
            $this->registerTarget($target['target']);
        }

        $this->renderInitial();
    }

    public function start(string $key): void
    {
        $this->setRow($key, self::STATE_ACTIVE, $this->stageLabel('pulling_source', $key));
    }

    public function stage(string $key, string $stage): void
    {
        $this->setRow($key, self::STATE_ACTIVE, $this->stageLabel($stage, $key));
    }

    public function done(string $key): void
    {
        $this->setRow($key, self::STATE_DONE, $this->stageLabel('done', $key));
    }

    public function fail(string $key, string $message): void
    {
        $this->setRow($key, self::STATE_FAILED, $this->stageLabel('failed', $key), $message);
    }

    /**
     * Append rows for targets discovered after the initial render.
     *
     * @param  list<array{target: string, node?: string|null, role?: string|null}>  $additionalTargets
     */
    public function extendWith(array $additionalTargets): void
    {
        $newKeys = [];

        foreach ($additionalTargets as $target) {
            $key = $target['target'];

            if (in_array($key, $this->order, true)) {
                continue;
            }

            $this->registerTarget($key);
            $newKeys[] = $key;
        }

        if ($newKeys === []) {
            return;
        }

        $this->renderExtension($newKeys);
    }

    public function finish(bool $success, string $footer): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        $this->tree->updateFooter(
            $this->output,
            '  '.SpinnerTreeRenderer::DIM.'└'.SpinnerTreeRenderer::RESET.'  '.SpinnerTreeRenderer::DIM.$footer.SpinnerTreeRenderer::RESET,
        );

        if ($this->output->isDecorated()) {
            $this->tree->showCursor($this->output);
        }

        $this->output->writeln('');
    }

    private function registerTarget(string $key): void
    {
        if (in_array($key, $this->order, true)) {
            return;
        }

        $this->order[] = $key;
        $this->rows[$key] = [
            'label' => $this->stageLabel('pulling_source', $key),
            'state' => self::STATE_PENDING,
            'message' => '',
        ];

        $this->labelWidth = max(
            $this->labelWidth,
            $this->maxLabelWidthForTarget($key),
        );
    }

    private function renderInitial(): void
    {
        $this->tree->renderFrame(
            $this->output,
            self::TITLE,
            array_map(fn (string $key): string => $this->rows[$key]['label'], $this->order),
            'Working...',
        );
        $this->rendered = true;
    }

    /**
     * @param  list<string>  $newKeys
     */
    private function renderExtension(array $newKeys): void
    {
        if (! $this->rendered) {
            $this->renderInitial();

            return;
        }

        if ($this->output->isDecorated()) {
            // Move cursor up to overwrite the existing footer line, write the
            // new rows, then re-emit the footer below them.
            $this->output->write("\e[1A\e[2K\r");
        }

        foreach ($newKeys as $key) {
            $this->output->writeln('  '.SpinnerTreeRenderer::DIM.'│'.SpinnerTreeRenderer::RESET);
            $this->output->writeln('  '.SpinnerTreeRenderer::DIM.'○  '.$this->rows[$key]['label'].SpinnerTreeRenderer::RESET);
        }

        $this->output->writeln('  '.SpinnerTreeRenderer::DIM.'│'.SpinnerTreeRenderer::RESET);
        $this->output->writeln('  '.SpinnerTreeRenderer::DIM.'└'.SpinnerTreeRenderer::RESET.'  '.SpinnerTreeRenderer::DIM.'Working...'.SpinnerTreeRenderer::RESET);
    }

    private function setRow(string $key, string $state, string $label, string $message = ''): void
    {
        if (! isset($this->rows[$key])) {
            return;
        }

        $this->rows[$key] = [
            'label' => $label,
            'state' => $state,
            'message' => $message,
        ];

        $this->labelWidth = max($this->labelWidth, mb_strlen($label));

        $this->repaintRow($key);
    }

    private function repaintRow(string $key): void
    {
        $index = array_search($key, $this->order, true);

        if ($index === false) {
            return;
        }

        $row = $this->rows[$key];
        $line = match ($row['state']) {
            self::STATE_ACTIVE => $this->summary->spinnerLine(
                "\e[36m◉\e[39m",
                $row['label'],
                $this->labelWidth,
            ),
            self::STATE_DONE => $this->summary->success($row['label'], $this->labelWidth, ''),
            self::STATE_FAILED => $this->summary->failure($row['label'], $this->labelWidth, $row['message']),
            default => $this->summary->idle($row['label'], $this->labelWidth),
        };

        $this->tree->updateLine($this->output, $index, count($this->order), $line);
    }

    private function maxLabelWidthForTarget(string $target): int
    {
        $stages = ['pulling_source', 'installing_dependencies', 'running_migrations', 'done', 'failed'];

        return max(array_map(
            fn (string $stage): int => mb_strlen($this->stageLabel($stage, $target)),
            $stages,
        ));
    }

    private function stageLabel(string $stage, string $target): string
    {
        return match ($stage) {
            'start', 'pulling_source' => "Pulling source - {$target}",
            'installing_dependencies' => "Installing dependencies - {$target}",
            'running_migrations' => "Running migrations - {$target}",
            'done' => "Done - {$target}",
            'failed', 'fail' => "Failed - {$target}",
            default => $target,
        };
    }
}
