<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Orbit\Core\Progress\StreamedStepTree;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drives the animated update progress tree via the shared {@see StreamedStepTree}.
 *
 * The active step animates (cyan ○/◉) while the binary download runs, then
 * settles to a green ● on success or a red ● with the failure output. The footer
 * shows the concrete result line.
 */
final class UpdateHumanProgressRenderer
{
    /** @var array<string, array{label: string, doneLabel: string}> */
    private const array STEP_LABELS = [
        'pull_source' => ['label' => 'Download binary', 'doneLabel' => 'Downloaded binary'],
    ];

    private const string SUCCESS_FOOTER = 'Updated local Orbit checkout.';

    private const string FAILURE_FOOTER = 'Failed to update local Orbit checkout.';

    private ?StreamedStepTree $tree = null;

    public function begin(OutputInterface $output): void
    {
        $this->tree = new StreamedStepTree($output);
        $this->tree->tree('Updating Orbit', array_map(
            static fn (string $key, array $labels): array => [
                'key' => $key,
                'label' => $labels['label'],
                'doneLabel' => $labels['doneLabel'],
            ],
            array_keys(self::STEP_LABELS),
            array_values(self::STEP_LABELS),
        ));

        $first = array_key_first(self::STEP_LABELS);

        if ($first !== null) {
            $this->tree->step($first, 'start');
        }
    }

    /**
     * @param  array{successful: bool, exit_code: int, output: string}  $result
     */
    public function recordStep(OutputInterface $output, int $index, string $step, array $result): void
    {
        if ($this->tree === null || ! isset(self::STEP_LABELS[$step])) {
            return;
        }

        if ($result['successful']) {
            $this->tree->step($step, 'done');

            return;
        }

        $message = $result['output'] !== ''
            ? $result['output']
            : "exit code {$result['exit_code']}";

        $this->tree->step($step, 'fail', $message);
    }

    public function finish(OutputInterface $output, bool $successful): void
    {
        $this->tree?->finish($successful ? self::SUCCESS_FOOTER : self::FAILURE_FOOTER, $successful);
    }
}
