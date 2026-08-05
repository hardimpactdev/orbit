<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Models\App;
use App\Models\AppSetupRun;
use App\Models\AppSetupStep;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\AppSetupStepRunner;
use App\Services\Apps\LaravelViteDevServerEnvironment;

final readonly class SetupApp
{
    public function __construct(
        private AppSetupStepRunner $stepRunner,
        private LaravelViteDevServerEnvironment $vite,
        private AppRuntimeContainerRenderer $runtimeRenderer,
    ) {}

    /**
     * @return array{
     *     app: string,
     *     instance: string,
     *     node: string,
     *     path: string,
     *     url: string,
     *     action: 'set_up'|'converged',
     *     setup_steps: array{status: string, count: int, message: string},
     * }
     */
    public function handle(App $app, Instance $instance, Node $node): array
    {
        $runtimeApp = $this->runtimeRenderer->runtimeAppForInstance($app, $instance);

        $setupResult = $this->runSetupSteps($runtimeApp, $instance, $node);

        return [
            'app' => $app->name,
            'instance' => $instance->name,
            'node' => $node->name,
            'path' => $runtimeApp->path,
            'url' => $runtimeApp->url(),
            'action' => $setupResult['status'] === 'completed' ? 'set_up' : 'converged',
            'setup_steps' => $setupResult,
        ];
    }

    /**
     * @param  (callable(string, AppSetupStep, int, int): void)|null  $onStepProgress
     * @return array{status: string, message: string, count: int}
     */
    public function runSetupSteps(
        App $app,
        Instance $instance,
        Node $node,
        ?callable $onStepProgress = null,
    ): array {
        $steps = AppSetupStep::query()
            ->where('instance_id', $instance->id)
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
            ->where('instance_id', $instance->id)
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
            'instance_id' => $instance->id,
            'status' => 'pending',
            'step_set_hash' => $stepSetHash,
            'started_at' => now(),
        ]);

        $success = $this->stepRunner->run(
            $run,
            $steps->all(),
            $app,
            $node,
            $this->appEnv($app, $instance, $node),
            $onStepProgress,
        );

        if (! $success) {
            $failedStep = $run
                ->runSteps()
                ->orderByDesc('id')
                ->first();

            $message = 'Instance setup failed.';
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
    private function appEnv(App $app, Instance $instance, Node $node): array
    {
        return (
            [
                'ORBIT_APP' => $app->name,
                'ORBIT_APP_INSTANCE' => $instance->name,
                'ORBIT_APP_PATH' => $app->path,
                'ORBIT_URL' => $app->url(),
                'ORBIT_PHP_VERSION' => $app->php_version,
            ] + $this->vite->shellVariables($app, $node)
        );
    }
}
