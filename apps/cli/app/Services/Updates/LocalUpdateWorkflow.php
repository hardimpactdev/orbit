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
        $steps = [
            'pull_source' => $this->updater->pullSource(...),
            'install_dependencies' => $this->updater->installDependencies(...),
            'run_migrations' => $this->updater->runMigrations(...),
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
                if ($key === 'pull_source' && $result['exit_code'] === 128) {
                    return new LocalUpdateResult(
                        status: LocalUpdateResult::STATUS_CHECKOUT_UNAVAILABLE,
                        stepResults: $stepResults,
                        checkoutPath: $this->checkoutPathResolver->resolve(),
                    );
                }

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
