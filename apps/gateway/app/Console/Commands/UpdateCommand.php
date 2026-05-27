<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\LogsCommandActivity;
use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Contracts\Loggable;
use App\Services\OrbitUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;

#[Signature('update {--json : Output as JSON}')]
#[Description('Update this Orbit checkout')]
class UpdateCommand extends Command implements Loggable
{
    use LogsCommandActivity;
    use WithSpinner;
    use WithStepTree;

    private const array STEP_LABELS = [
        'pull_source' => 'Pull source',
        'install_dependencies' => 'Install dependencies',
        'run_migrations' => 'Run migrations',
    ];

    private ?string $activityStatus = null;

    private ?string $activityFailedStep = null;

    public function handle(OrbitUpdater $updater): int
    {
        $this->bootActivityLog();

        try {
            return $this->executeUpdate($updater);
        } finally {
            $this->finishActivityLog();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter([
            'scope' => 'local',
            'target' => 'local',
            'status' => $this->activityStatus,
            'failed_step' => $this->activityFailedStep,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function description(): ?string
    {
        return match ($this->activityStatus) {
            'completed' => 'Local Orbit checkout updated',
            'failed' => 'Local Orbit checkout update failed',
            default => 'Local Orbit checkout update attempted',
        };
    }

    private function executeUpdate(OrbitUpdater $updater): int
    {
        if ($this->wantsJson()) {
            return $this->executeUpdateJson($updater);
        }

        return $this->executeUpdateHuman($updater);
    }

    private function executeUpdateJson(OrbitUpdater $updater): int
    {
        $stepResults = [];

        $steps = [
            'pull_source' => $updater->pullSource(...),
            'install_dependencies' => $updater->installDependencies(...),
            'run_migrations' => $updater->runMigrations(...),
        ];

        foreach ($steps as $key => $step) {
            $result = $step();
            $stepResults[$key] = $result->successful() ? 'completed' : 'failed';

            if (! $result->successful()) {
                $this->captureActivityFailure($key);

                if ($key === 'pull_source' && $result->exitCode() === 128) {
                    return $this->jsonError(
                        code: 'local_checkout_unavailable',
                        message: 'Local Orbit checkout cannot be updated.',
                        meta: ['path' => repo_path()],
                    );
                }

                return $this->jsonError(
                    code: 'local_update_failed',
                    message: 'Failed to update local Orbit checkout.',
                    data: ['output' => trim($result->errorOutput() ?: $result->output())],
                    meta: ['failed_step' => $key],
                );
            }
        }

        $this->activityStatus = 'completed';

        return $this->jsonSuccess($stepResults);
    }

    private function executeUpdateHuman(OrbitUpdater $updater): int
    {
        $failedResult = null;
        $failedKey = null;

        $steps = [
            [
                'key' => 'pull_source',
                'label' => self::STEP_LABELS['pull_source'],
                'run' => function () use ($updater, &$failedResult, &$failedKey): string {
                    $result = $updater->pullSource();

                    if ($result->successful()) {
                        return '';
                    }

                    $failedResult = $result;
                    $failedKey = 'pull_source';

                    return 'fail:'.$this->failureMessage($result);
                },
            ],
            [
                'key' => 'install_dependencies',
                'label' => self::STEP_LABELS['install_dependencies'],
                'run' => function () use ($updater, &$failedResult, &$failedKey): string {
                    $result = $updater->installDependencies();

                    if ($result->successful()) {
                        return '';
                    }

                    $failedResult = $result;
                    $failedKey = 'install_dependencies';

                    return 'fail:'.$this->failureMessage($result);
                },
            ],
            [
                'key' => 'run_migrations',
                'label' => self::STEP_LABELS['run_migrations'],
                'run' => function () use ($updater, &$failedResult, &$failedKey): string {
                    $result = $updater->runMigrations();

                    if ($result->successful()) {
                        return '';
                    }

                    $failedResult = $result;
                    $failedKey = 'run_migrations';

                    return 'fail:'.$this->failureMessage($result);
                },
            ],
        ];

        $exitCode = $this->runStepTree(
            'Updating Orbit',
            $steps,
            doneFooter: 'Updated local Orbit checkout.',
            failFooter: 'Failed to update local Orbit checkout.',
        );

        if ($exitCode === self::SUCCESS) {
            $this->activityStatus = 'completed';
            $this->info('Updated local Orbit checkout.');

            return self::SUCCESS;
        }

        $this->captureActivityFailure($failedKey ?? 'unknown');
        $this->error('Failed to update local Orbit checkout.');

        if ($failedResult !== null) {
            $output = trim($failedResult->errorOutput() ?: $failedResult->output());

            if ($output !== '') {
                $this->line($output);
            }
        }

        return self::FAILURE;
    }

    private function failureMessage(ProcessResult $result): string
    {
        $message = trim($result->errorOutput() ?: $result->output());

        return $message !== '' ? $message : 'exit code '.$result->exitCode();
    }

    private function captureActivityFailure(string $failedStep): void
    {
        $this->activityStatus = 'failed';
        $this->activityFailedStep = $failedStep;
    }

    private function finishActivityLog(): void
    {
        try {
            $this->finalizeActivityLog();
        } catch (\Throwable) {
            // Activity logging must not change the documented update result.
        }
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string, string>  $stepResults
     */
    private function jsonSuccess(array $stepResults): int
    {
        $steps = [];

        foreach ($stepResults as $name => $status) {
            $steps[] = [
                'name' => $name,
                'status' => $status,
            ];
        }

        $this->line(json_encode([
            'success' => [
                'data' => [
                    'update' => [
                        'scope' => 'local',
                        'target' => 'local',
                        'status' => 'completed',
                        'steps' => $steps,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function jsonError(string $code, string $message, array $data = [], array $meta = []): int
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'meta' => $meta,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        $this->line(json_encode([
            'error' => $error,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }
}
