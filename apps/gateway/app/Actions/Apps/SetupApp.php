<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Models\App;
use App\Models\AppSetupRun;
use App\Models\AppSetupStep;
use App\Models\Node;
use App\Services\Apps\AppSetupStepRunner;
use RuntimeException;

final readonly class SetupApp
{
    public function __construct(
        private AppSetupStepRunner $stepRunner,
    ) {}

    /**
     * @return array{
     *     app: string,
     *     node: string,
     *     path: string,
     *     url: string,
     *     action: 'set_up'|'converged',
     *     setup_steps: array{status: string, count: int, message: string},
     * }
     */
    public function handle(App $app): array
    {
        $app->loadMissing('node');

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $setupResult = $this->runSetupSteps($app, $node);

        return [
            'app' => $app->name,
            'node' => $node->name,
            'path' => $app->path,
            'url' => $app->url(),
            'action' => $setupResult['status'] === 'completed' ? 'set_up' : 'converged',
            'setup_steps' => $setupResult,
        ];
    }

    /**
     * @param  (callable(string, AppSetupStep, int, int): void)|null  $onStepProgress
     * @return array{status: string, message: string, count: int}
     */
    public function runSetupSteps(App $app, Node $node, ?callable $onStepProgress = null): array
    {
        $steps = AppSetupStep::query()
            ->where('app_id', $app->id)
            ->orderBy('sort_order')
            ->get();

        if ($steps->isEmpty()) {
            return [
                'status' => 'skipped',
                'message' => 'No setup steps configured',
                'count' => 0,
            ];
        }

        $stepSetHash = $this->computeStepSetHash($steps->all());

        $latestSuccessfulRun = AppSetupRun::query()
            ->where('app_id', $app->id)
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        if ($latestSuccessfulRun instanceof AppSetupRun && $latestSuccessfulRun->step_set_hash === $stepSetHash) {
            return [
                'status' => 'skipped',
                'message' => 'Already up to date',
                'count' => 0,
            ];
        }

        $run = AppSetupRun::query()->create([
            'app_id' => $app->id,
            'status' => 'pending',
            'step_set_hash' => $stepSetHash,
            'started_at' => now(),
        ]);

        $success = $this->stepRunner->run($run, $steps->all(), $app, $node, $this->appEnv($app), $onStepProgress);

        if (! $success) {
            $failedStep = $run->runSteps()
                ->orderByDesc('id')
                ->first();

            $message = 'App setup failed.';
            if ($failedStep !== null) {
                $message = "Setup step failed: {$failedStep->command}";
                if ($failedStep->output !== null && $failedStep->output !== '') {
                    $message .= "\n{$failedStep->output}";
                }
            }

            return [
                'status' => 'failed',
                'message' => $message,
                'count' => 0,
            ];
        }

        $count = $steps->count();

        return [
            'status' => 'completed',
            'message' => $count === 1 ? '1 step' : "{$count} steps",
            'count' => $count,
        ];
    }

    /**
     * @param  list<AppSetupStep>  $steps
     */
    private function computeStepSetHash(array $steps): string
    {
        $data = array_map(fn (AppSetupStep $step): array => [
            'command' => $step->command,
            'timeout' => $step->timeoutSeconds(),
        ], $steps);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    private function appEnv(App $app): array
    {
        $domain = parse_url($app->url(), PHP_URL_HOST) ?: $app->name;

        return [
            'ORBIT_APP' => $app->name,
            'ORBIT_APP_PATH' => $app->path,
            'ORBIT_URL' => $app->url(),
            'ORBIT_PHP_VERSION' => $app->php_version,
            'VITE_APP_URL' => $app->url(),
            'VITE_VALET_HOST' => $domain,
        ];
    }
}
