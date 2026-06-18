<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Orbit\Core\Progress\StreamedStepTree;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drives the animated `orbit update` progress tree via {@see StreamedStepTree}.
 *
 * The tree starts single-step (`Checking for updates`) so the common
 * already-current case stays one fast step. When the gate allows an update the
 * tree is upgraded to the full Downloading → Replacing → Running doctor row set
 * the moment the download begins. Step messages carry the documented status
 * vocabulary; the footer states the concrete outcome.
 */
final class UpdateHumanProgressRenderer
{
    private const string TITLE = 'Updating Orbit';

    /** Ordered labels keyed by the runner's stable step identifiers. */
    private const array STEP_LABELS = [
        LocalUpdateRunner::STEP_CHECK => 'Checking for updates',
        LocalUpdateRunner::STEP_DOWNLOAD => 'Downloading binary',
        LocalUpdateRunner::STEP_REPLACE => 'Replacing binary',
        LocalUpdateRunner::STEP_DOCTOR => 'Running doctor',
    ];

    private const string SUCCESS_FOOTER = 'Success: Updated';

    private const string FAILURE_FOOTER = 'Failed to update local Orbit checkout.';

    private const string CHECKOUT_UNAVAILABLE_FOOTER = 'Local Orbit checkout cannot be updated.';

    private ?StreamedStepTree $tree = null;

    private bool $expanded = false;

    private string $checkDoneMessage = 'Done: new version available';

    /**
     * The check row settles `done` only when the gate allows an update, which is
     * always immediately followed by either the reveal (proceed) or a skip
     * footer. Buffering the settle keeps a single, accurate `Checking for
     * updates` row across the tree upgrade instead of printing it twice.
     */
    private bool $checkDonePending = false;

    public function __construct(
        private readonly OutputInterface $output,
    ) {}

    /**
     * Paint the initial single-step tree before the version check begins.
     */
    public function begin(): void
    {
        $this->tree = new StreamedStepTree($this->output);
        $this->tree->tree(self::TITLE, [
            $this->row(LocalUpdateRunner::STEP_CHECK),
        ]);
    }

    /**
     * Apply one runner step event to the tree.
     *
     * `$status` is a {@see StreamedStepTree} status: `progress`, `done`, `skip`,
     * or `fail`.
     */
    public function recordStep(string $step, string $status, ?string $message): void
    {
        if ($this->tree === null || ! isset(self::STEP_LABELS[$step])) {
            return;
        }

        // Buffer the check `done` settle: it is always followed by either the
        // reveal (proceed) or a skip footer, both of which render the resolved
        // check row exactly once.
        if ($step === LocalUpdateRunner::STEP_CHECK && $status === 'done') {
            if ($message !== null && $message !== '') {
                $this->checkDoneMessage = $message;
            }

            $this->checkDonePending = true;

            return;
        }

        // Reveal the download/replace/doctor rows the first time the update
        // proceeds past the check step.
        if (! $this->expanded && $step !== LocalUpdateRunner::STEP_CHECK) {
            $this->expand();
        }

        $this->tree->step($step, $status, $message);
    }

    public function finishSuccess(LocalUpdateResult $result): void
    {
        $this->flushPendingCheck();

        $from = $result->fromVersion ?? '';
        $to = $result->toVersion ?? $result->latestVersion ?? '';
        $footer = $from !== '' && $to !== ''
            ? self::SUCCESS_FOOTER." from {$from} to {$to}"
            : self::SUCCESS_FOOTER.'.';

        $this->tree?->finish($footer, true);
    }

    public function finishSkipped(LocalUpdateResult $result): void
    {
        $this->flushPendingCheck();

        $footer = match ($result->status) {
            LocalUpdateResult::STATUS_SKIPPED_ALREADY => 'Skipped: '.($result->latestVersion ?? $result->fromVersion ?? 'Orbit').' is already installed',
            LocalUpdateResult::STATUS_SKIPPED_GATEWAY_BEHIND => 'Skipped: please update your gateway first',
            default => 'Skipped.',
        };

        // Skips are successful (exit 0) outcomes; render the footer green.
        $this->tree?->finish($footer, true);
    }

    public function finishFailure(): void
    {
        $this->flushPendingCheck();

        $this->tree?->finish(self::FAILURE_FOOTER, false);
    }

    /**
     * Render the checkout-unavailable case: no tree was meaningfully advanced;
     * print a single prose line instead.
     */
    public function renderCheckoutUnavailable(): void
    {
        if ($this->tree !== null && $this->tree->isStarted()) {
            $this->tree->finish(self::CHECKOUT_UNAVAILABLE_FOOTER, false);

            return;
        }

        $this->output->writeln(self::CHECKOUT_UNAVAILABLE_FOOTER);
    }

    /**
     * Print captured failure output beneath the tree.
     */
    public function writeOutput(string $output): void
    {
        if ($output !== '') {
            $this->output->writeln($output);
        }
    }

    /**
     * Repaint the tree with the full proceed-phase row set. The check row is
     * preserved as already done.
     */
    private function expand(): void
    {
        if ($this->tree === null) {
            return;
        }

        $this->expanded = true;
        $this->checkDonePending = false;
        $this->tree->tree(self::TITLE, array_map(
            $this->row(...),
            array_keys(self::STEP_LABELS),
        ));
        $this->tree->step(LocalUpdateRunner::STEP_CHECK, 'done', $this->checkDoneMessage);
    }

    /**
     * Settle a buffered check `done` row for outcomes that stop at the check step
     * (the gateway-first skip), so the footer is preceded by the resolved row.
     */
    private function flushPendingCheck(): void
    {
        if ($this->checkDonePending && $this->tree !== null) {
            $this->tree->step(LocalUpdateRunner::STEP_CHECK, 'done', $this->checkDoneMessage);
        }

        $this->checkDonePending = false;
    }

    /**
     * @return array{key: string, label: string, doneLabel: string}
     */
    private function row(string $key): array
    {
        return [
            'key' => $key,
            'label' => self::STEP_LABELS[$key],
            'doneLabel' => self::STEP_LABELS[$key],
        ];
    }
}
