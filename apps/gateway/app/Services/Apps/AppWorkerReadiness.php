<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\AppWorkerReadinessResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;

final readonly class AppWorkerReadiness
{
    /**
     * Tokens the probe script writes to stdout. Each token represents an
     * independent piece of evidence; the service only reports ready when
     * every required token is present.
     */
    public const string InstalledToken = 'octane:installed';

    public const string WorkerFileToken = 'frankenphp-worker-file:present';

    public const string ConfiguredToken = 'frankenphp:configured';

    public function __construct(
        private RemoteAppWorkerReadinessProbe $probe,
    ) {}

    public function assess(App $app): AppWorkerReadinessResult
    {
        $runtime = $app->runtimeKind();

        if ($runtime !== AppRuntimeKind::Php) {
            return AppWorkerReadinessResult::notReady(
                code: 'app.worker_unsupported_runtime',
                message: "Worker mode requires runtime=php; app '{$app->name}' uses '{$runtime->value}'.",
                missing: ['runtime=php'],
                meta: ['runtime' => $runtime->value],
            );
        }

        $node = $app->node;

        if ($node === null) {
            return AppWorkerReadinessResult::notReady(
                code: 'app.worker_unknown_node',
                message: "App '{$app->name}' has no owning node; cannot validate worker readiness.",
                missing: ['owning_node'],
            );
        }

        $appPath = rtrim($app->path, '/');

        if ($appPath === '') {
            return AppWorkerReadinessResult::notReady(
                code: 'app.worker_missing_path',
                message: "App '{$app->name}' has no source path; cannot validate worker readiness.",
                missing: ['app_path'],
            );
        }

        $workerFileRelative = AppRuntimeContainerRenderer::workerFileRelativeToSource($app);

        $stdout = trim($this->probe->stdout($node, $appPath, $workerFileRelative));

        $missing = [];

        if (! str_contains($stdout, self::InstalledToken)) {
            $missing[] = 'vendor/laravel/octane';
        }

        if (! str_contains($stdout, self::WorkerFileToken)) {
            $missing[] = $workerFileRelative;
        }

        if (! str_contains($stdout, self::ConfiguredToken)) {
            $missing[] = 'octane.server=frankenphp';
        }

        if ($missing !== []) {
            return AppWorkerReadinessResult::notReady(
                code: 'app.worker_readiness_failed',
                message: "App '{$app->name}' is not ready for worker mode.",
                missing: $missing,
                meta: [
                    'probe_output' => $stdout,
                    'worker_file' => $workerFileRelative,
                ],
            );
        }

        return AppWorkerReadinessResult::ready();
    }
}
