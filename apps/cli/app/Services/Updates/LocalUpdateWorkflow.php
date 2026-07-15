<?php

declare(strict_types=1);

namespace App\Services\Updates;

final readonly class LocalUpdateWorkflow
{
    public function __construct(
        private RunsLocalUpdate $updater,
        private CheckoutPathResolver $checkoutPathResolver,
    ) {}

    /**
     * @param  callable(int, string, array{successful: bool, exit_code: int, output: string}): void|null  $onStep
     */
    public function run(?callable $onStep = null): LocalUpdateResult
    {
        $installRoot = $this->checkoutPathResolver->resolve();

        if (! is_dir($installRoot)) {
            return new LocalUpdateResult(
                status: LocalUpdateResult::STATUS_INSTALLATION_UNAVAILABLE,
                installationPath: $installRoot,
            );
        }

        $steps = [
            'pull_source' => $this->updater->pullSource(...),
        ];

        $stepResults = [];
        $index = 0;

        foreach ($steps as $key => $step) {
            $result = $step();
            $stepResults[$key] = $result['successful'] ? 'completed' : 'failed';

            if ($onStep !== null) {
                $onStep($index, $key, $result);
            }

            if (! $result['successful']) {
                return new LocalUpdateResult(
                    status: LocalUpdateResult::STATUS_FAILED,
                    stepResults: $stepResults,
                    failedStep: $key,
                    output: $result['output'],
                );
            }

            $index++;
        }

        return new LocalUpdateResult(
            status: LocalUpdateResult::STATUS_COMPLETED,
            stepResults: $stepResults,
        );
    }
}
