<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OrbitUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;

#[Signature('update {--json : Output as JSON}')]
#[Description('Update this Orbit checkout')]
class UpdateCommand extends Command
{
    private const array STEP_LABELS = [
        'pull_source' => 'Pull source',
        'install_dependencies' => 'Install dependencies',
        'run_migrations' => 'Run migrations',
    ];

    public function handle(OrbitUpdater $updater): int
    {
        $steps = [
            'pull_source' => fn (): ProcessResult => $updater->pullSource(),
            'install_dependencies' => fn (): ProcessResult => $updater->installDependencies(),
            'run_migrations' => fn (): ProcessResult => $updater->runMigrations(),
        ];

        $stepResults = [];

        if ($this->wantsJson()) {
            foreach ($steps as $key => $step) {
                $result = $step();
                $stepResults[$key] = $result->successful() ? 'completed' : 'failed';

                if (! $result->successful()) {
                    if ($key === 'pull_source' && $result->exitCode() === 128) {
                        return $this->jsonError(
                            code: 'local_checkout_unavailable',
                            message: 'Local Orbit checkout cannot be updated.',
                            meta: ['path' => base_path()],
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

            return $this->jsonSuccess($stepResults);
        }

        $this->renderProgressTree();

        foreach ($steps as $key => $step) {
            $result = $step();
            $stepResults[$key] = $result->successful() ? 'completed' : 'failed';
            $this->updateProgressTree($stepResults);

            if (! $result->successful()) {
                $this->line('');
                $this->error('Failed to update local Orbit checkout.');
                $output = trim($result->errorOutput() ?: $result->output());

                if ($output !== '') {
                    $this->line($output);
                }

                return self::FAILURE;
            }
        }

        $this->line('');
        $this->info('Updated local Orbit checkout.');

        return self::SUCCESS;
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

    private function renderProgressTree(): void
    {
        $this->line('┌ Update Orbit');

        foreach (self::STEP_LABELS as $label) {
            $this->line("○ {$label}");
        }

        $this->line('└ Local Orbit checkout updated');
    }

    /**
     * @param  array<string, string>  $stepResults
     */
    private function updateProgressTree(array $stepResults): void
    {
        $lines = count(self::STEP_LABELS) + 2;

        for ($i = 0; $i < $lines; $i++) {
            $this->output->write("\e[1A\e[2K");
        }

        $this->line('┌ Update Orbit');

        foreach (self::STEP_LABELS as $key => $label) {
            $status = $stepResults[$key] ?? 'pending';
            $symbol = match ($status) {
                'completed' => '●',
                'failed' => '✖',
                default => '○',
            };
            $this->line("{$symbol} {$label}");
        }

        $hasFailure = in_array('failed', $stepResults, true);
        $footer = $hasFailure ? 'Failed' : 'Local Orbit checkout updated';
        $this->line("└ {$footer}");
    }
}
