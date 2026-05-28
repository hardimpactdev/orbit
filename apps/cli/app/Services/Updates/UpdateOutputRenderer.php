<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Symfony\Component\Console\Output\OutputInterface;

final readonly class UpdateOutputRenderer
{
    public function __construct(
        private LocalUpdateWorkflow $workflow,
        private UpdateHumanProgressRenderer $progress,
    ) {}

    public function render(OutputInterface $output, bool $wantsJson): int
    {
        if (! $wantsJson) {
            $this->progress->begin($output);
        }

        $result = $this->workflow->run(function (int $index, string $step, array $stepResult) use ($output, $wantsJson): void {
            if ($wantsJson) {
                return;
            }

            $this->progress->recordStep($output, $index, $step, $stepResult);
        });

        if (! $wantsJson) {
            $this->progress->finish($output, $result->successful());
        }

        if ($wantsJson) {
            return $this->renderJson($output, $result);
        }

        return $this->renderHuman($output, $result);
    }

    private function renderJson(OutputInterface $output, LocalUpdateResult $result): int
    {
        if ($result->status === LocalUpdateResult::STATUS_COMPLETED) {
            $output->writeln(json_encode(
                UpdateEnvelopeBuilder::success($result->stepResults),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return 0;
        }

        if ($result->status === LocalUpdateResult::STATUS_CHECKOUT_UNAVAILABLE) {
            $output->writeln(json_encode(
                UpdateEnvelopeBuilder::failure(
                    'local_checkout_unavailable',
                    'Local Orbit checkout cannot be updated.',
                    meta: ['path' => $result->checkoutPath ?? ''],
                ),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return 1;
        }

        $data = $result->output !== '' ? ['output' => $result->output] : [];

        $output->writeln(json_encode(
            UpdateEnvelopeBuilder::failure(
                'local_update_failed',
                'Failed to update local Orbit checkout.',
                $data,
                ['failed_step' => $result->failedStep ?? 'unknown'],
            ),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        return 1;
    }

    private function renderHuman(OutputInterface $output, LocalUpdateResult $result): int
    {
        if ($result->status === LocalUpdateResult::STATUS_COMPLETED) {
            $output->writeln('Updated local Orbit checkout.');

            return 0;
        }

        $output->writeln('Failed to update local Orbit checkout.');

        if ($result->status === LocalUpdateResult::STATUS_CHECKOUT_UNAVAILABLE) {
            $output->writeln('Local Orbit checkout cannot be updated.');

            return 1;
        }

        if ($result->output !== '') {
            $output->writeln($result->output);
        }

        return 1;
    }
}
