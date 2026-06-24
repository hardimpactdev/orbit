<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\ProgressReporter;
use App\Models\App;
use App\Models\AppSetupStep;
use App\Models\Node;
use RuntimeException;
use Throwable;

final class SetupAppProgressPlan
{
    /** @var array{status: string, message: string, count: int} */
    private array $setupResult = [
        'status' => 'skipped',
        'message' => 'No setup steps configured',
        'count' => 0,
    ];

    /** @var array{code: string, message: string, meta: array<string, mixed>}|null */
    private ?array $failure = null;

    private ?ProgressReporter $reporter = null;

    public function __construct(
        private readonly SetupApp $setupApp,
        private readonly App $app,
        private readonly Node $node,
    ) {}

    public function title(): string
    {
        return 'Setting Up App';
    }

    /**
     * @return list<array{key: string, label: string, doneLabel: string, run: callable(): string}>
     */
    public function steps(): array
    {
        $steps = [
            [
                'key' => 'resolve_app',
                'label' => 'Resolve app',
                'doneLabel' => 'Resolved app',
                'run' => fn (): string => $this->app->name,
            ],
        ];

        if ($this->hasSetupSteps()) {
            $steps[] = [
                'key' => 'run_app_setup_steps',
                'label' => 'Run app setup steps',
                'doneLabel' => 'Ran app setup steps',
                'run' => function (): string {
                    $this->setupResult = $this->setupApp->runSetupSteps(
                        $this->app,
                        $this->node,
                        $this->reportSetupStepProgress(...),
                    );

                    if ($this->setupResult['status'] === 'failed') {
                        $this->failure = [
                            'code' => 'app.setup_step_failed',
                            'message' => $this->setupResult['message'],
                            'meta' => [
                                'phase' => 'setup_steps',
                                'node' => $this->node->name,
                                'path' => $this->app->path,
                            ],
                        ];

                        throw new RuntimeException($this->setupResult['message']);
                    }

                    return $this->setupResult['message'];
                },
            ];
        }

        return $steps;
    }

    public function runForReporter(ProgressReporter $reporter): int
    {
        $this->reporter = $reporter;
        $steps = $this->steps();

        $reporter->tree($this->title(), array_map(static fn (array $step): array => [
            'key' => $step['key'],
            'label' => $step['label'],
            'doneLabel' => $step['doneLabel'],
        ], $steps));

        foreach ($steps as $step) {
            $reporter->stepStart($step['key']);

            try {
                $message = (string) ($step['run'])();
            } catch (Throwable $exception) {
                $this->failure ??= [
                    'code' => 'app.setup_failed',
                    'message' => $exception->getMessage(),
                    'meta' => [
                        'phase' => 'setup',
                        'node' => $this->node->name,
                    ],
                ];

                $reporter->stepFail($step['key'], $exception->getMessage());
                $this->reporter = null;

                return 1;
            }

            if (str_starts_with($message, 'skip:')) {
                $reporter->stepSkip($step['key'], substr($message, 5));

                continue;
            }

            $reporter->stepDone($step['key'], $message === '' ? null : $message);
        }

        $this->reporter = null;

        return 0;
    }

    private function reportSetupStepProgress(string $event, AppSetupStep $step, int $index, int $count): void
    {
        if ($this->reporter === null) {
            return;
        }

        $command = str($step->command)->squish()->limit(80)->toString();
        $message = match ($event) {
            'completed' => "Completed setup step {$index}/{$count}: {$command}",
            'failed' => "Failed setup step {$index}/{$count}: {$command}",
            default => "Running setup step {$index}/{$count}: {$command}",
        };

        $this->reporter->stepProgress('run_app_setup_steps', 'progress', $message);
    }

    public function doneFooter(): string
    {
        return "App ready and available at: {$this->app->url()}";
    }

    public function failFooter(): string
    {
        return "Failed to set up app '{$this->app->name}'.";
    }

    /**
     * @return array{code: string, message: string, meta: array<string, mixed>}|null
     */
    public function failure(): ?array
    {
        return $this->failure;
    }

    /**
     * @return array{
     *     app: string,
     *     node: string,
     *     path: string,
     *     url: string,
     *     action: 'set_up'|'converged',
     *     setup_steps: array{status: string, message: string, count: int},
     * }
     */
    public function result(): array
    {
        return [
            'app' => $this->app->name,
            'node' => $this->node->name,
            'path' => $this->app->path,
            'url' => $this->app->url(),
            'action' => $this->setupResult['status'] === 'completed' ? 'set_up' : 'converged',
            'setup_steps' => $this->setupResult,
        ];
    }

    private function hasSetupSteps(): bool
    {
        return AppSetupStep::query()
            ->where('app_id', $this->app->id)
            ->exists();
    }
}
