<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Symfony\Component\Console\Output\OutputInterface;

final readonly class UpdateOutputRenderer
{
    public function __construct(
        private LocalUpdateRunner $runner,
    ) {}

    public function render(OutputInterface $output, bool $wantsJson): int
    {
        return $wantsJson
            ? $this->renderJson($output)
            : $this->renderHuman($output);
    }

    private function renderHuman(OutputInterface $output): int
    {
        $progress = new UpdateHumanProgressRenderer($output);
        $progress->begin();

        $result = $this->runner->run(
            function (string $step, string $status, ?string $message) use ($progress): void {
                $progress->recordStep($step, $status, $message);
            },
        );

        return match ($result->status) {
            LocalUpdateResult::STATUS_COMPLETED => $this->finishHumanSuccess($progress, $result),
            LocalUpdateResult::STATUS_SKIPPED_ALREADY,
            LocalUpdateResult::STATUS_SKIPPED_GATEWAY_BEHIND => $this->finishHumanSkip($progress, $result),
            LocalUpdateResult::STATUS_CHECKOUT_UNAVAILABLE => $this->finishHumanCheckoutUnavailable($progress),
            default => $this->finishHumanFailure($progress, $result),
        };
    }

    private function finishHumanSuccess(UpdateHumanProgressRenderer $progress, LocalUpdateResult $result): int
    {
        $progress->finishSuccess($result);

        return 0;
    }

    private function finishHumanSkip(UpdateHumanProgressRenderer $progress, LocalUpdateResult $result): int
    {
        $progress->finishSkipped($result);

        return 0;
    }

    private function finishHumanCheckoutUnavailable(UpdateHumanProgressRenderer $progress): int
    {
        $progress->renderCheckoutUnavailable();

        return 1;
    }

    private function finishHumanFailure(UpdateHumanProgressRenderer $progress, LocalUpdateResult $result): int
    {
        $progress->finishFailure();
        $progress->writeOutput($result->output);

        return 1;
    }

    private function renderJson(OutputInterface $output): int
    {
        $result = $this->runner->run();

        return match ($result->status) {
            LocalUpdateResult::STATUS_COMPLETED,
            LocalUpdateResult::STATUS_SKIPPED_ALREADY,
            LocalUpdateResult::STATUS_SKIPPED_GATEWAY_BEHIND => $this->writeJson($output, UpdateEnvelopeBuilder::success($result), 0),
            LocalUpdateResult::STATUS_CHECKOUT_UNAVAILABLE => $this->writeJson(
                $output,
                UpdateEnvelopeBuilder::failure(
                    'local_checkout_unavailable',
                    'Local Orbit checkout cannot be updated.',
                    meta: ['path' => $result->checkoutPath ?? ''],
                ),
                1,
            ),
            LocalUpdateResult::STATUS_CHECK_FAILED => $this->writeJson(
                $output,
                UpdateEnvelopeBuilder::failure(
                    'local_update_check_failed',
                    'Failed to check for Orbit updates.',
                    $result->output !== '' ? ['output' => $result->output] : [],
                    ['failed_step' => $result->failedStep ?? LocalUpdateRunner::STEP_CHECK],
                ),
                1,
            ),
            default => $this->writeJson(
                $output,
                UpdateEnvelopeBuilder::failure(
                    'local_update_failed',
                    'Failed to update local Orbit checkout.',
                    $result->output !== '' ? ['output' => $result->output] : [],
                    ['failed_step' => $result->failedStep ?? 'unknown'],
                ),
                1,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function writeJson(OutputInterface $output, array $envelope, int $exitCode): int
    {
        $output->writeln(json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $exitCode;
    }
}
