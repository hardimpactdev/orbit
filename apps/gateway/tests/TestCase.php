<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\RemoteShell;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Operations\UpdateLeaseHeartbeatProcess;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteExecutorOutputRedactor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Workspaces\WorkspaceReadinessProbe;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Fakes\InlineUpdateLeaseHeartbeatProcess;
use Tests\Fakes\NullRemoteShell;
use Tests\Fakes\RemoteShellBackedInternalExecutor;
use Tests\Fakes\RemoteShellBackedRemoteExecutor;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (env('ORBIT_E2E') === '1') {
            return;
        }

        $this->isolateStoragePathPerWorker();

        $this->app->instance(RemoteShell::class, new NullRemoteShell);
        $this->app->bind(RemoteExecutor::class, RemoteShellBackedRemoteExecutor::class);
        $this->app->bind(RunsInternalCommands::class, RemoteShellBackedInternalExecutor::class);
        $this->app->bind(UpdateLeaseHeartbeatProcess::class, InlineUpdateLeaseHeartbeatProcess::class);
        $this->app->bind(RemoteLocalExecutor::class, function (Application $app): RemoteLocalExecutor {
            $secret = config('app.key');

            if (! is_string($secret) || trim($secret) === '') {
                throw new RuntimeException('Application key is not configured for operation token signing.');
            }

            return new RemoteLocalExecutor(
                commands: $app->make(LocalExecutorCommandBuilder::class),
                operationTokens: $app->make(OperationTokenFactory::class),
                activityLogger: $app->make(ActivityLogger::class),
                operationRuns: $app->make(OperationRunRecorder::class),
                outputRedactor: $app->make(RemoteExecutorOutputRedactor::class),
                applicationKey: $secret,
            );
        });

        // `maxAttempts: 0` makes `probe()` skip its HTTP loop entirely and
        // return the initial `not_run` snapshot. Setup-related tests still
        // exercise the rest of the workflow; tests that exercise the probe
        // itself instantiate it directly with their own attempt count.
        $this->app->instance(
            WorkspaceReadinessProbe::class,
            new WorkspaceReadinessProbe(maxAttempts: 0, retryDelayMilliseconds: 0),
        );
    }

    /**
     * Give each ParaTest worker its own `storage/` directory so tests that
     * write to `storage_path(...)` cannot race when running `pest --parallel`.
     */
    private function isolateStoragePathPerWorker(): void
    {
        $token = getenv('TEST_TOKEN');
        if ($token === false || $token === '') {
            return;
        }

        $workerStorage = $this->app->basePath('storage/framework/testing/worker-'.$token);
        @mkdir($workerStorage.'/app', recursive: true);
        @mkdir($workerStorage.'/framework/cache', recursive: true);
        @mkdir($workerStorage.'/framework/sessions', recursive: true);
        @mkdir($workerStorage.'/framework/testing', recursive: true);
        @mkdir($workerStorage.'/framework/views', recursive: true);
        @mkdir($workerStorage.'/logs', recursive: true);

        $this->app->useStoragePath($workerStorage);
    }
}
