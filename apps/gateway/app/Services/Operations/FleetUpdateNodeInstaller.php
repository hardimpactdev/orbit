<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationRun;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RunsInternalCommands;
use RuntimeException;

final readonly class FleetUpdateNodeInstaller
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
        private OperationRunRecorder $operationRuns,
        private FleetUpdateInstallResultInspector $installResults,
    ) {}

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{timeout: int, input: string, metadata: array<string, string>, cwd?: string, environment?: array<string, string>, bind_application_key?: bool, bind_input?: bool}  $transportOptions
     */
    public function run(
        OperationRun $operationRun,
        Node $node,
        string $eventKey,
        array $commandOptions,
        array $transportOptions,
    ): ?RemoteShellResult {
        $fullInput = $transportOptions['input'];

        if ($this->installResults->requiresBootstrapCliStage($fullInput)) {
            $bootstrapTransportOptions = $transportOptions;
            $bootstrapTransportOptions['input'] = $this->installResults->cliOnlyInstallPayloadJson($fullInput);

            $bootstrapResult = $this->runCliInstallWithSelfUpdateRetry(
                $node,
                $commandOptions,
                $bootstrapTransportOptions,
            );

            if ($bootstrapResult instanceof RemoteShellResult && ! $bootstrapResult->successful()) {
                return $bootstrapResult;
            }

            $this->operationRuns->appendStep(
                $operationRun->id,
                $eventKey,
                'running',
                $this->installResults->expectsAgentInstall($fullInput)
                    ? 'Installing Orbit Agent artifact'
                    : 'Completing install with new CLI',
            );
        }

        $result = $this->runCliInstallWithSelfUpdateRetry($node, $commandOptions, $transportOptions);

        if (! $result instanceof RemoteShellResult) {
            return null;
        }

        if (! $result->successful()) {
            return $result;
        }

        if (! $this->installResults->expectedAgentInstallWasConfirmed($result, $fullInput)) {
            throw new RuntimeException('Orbit Agent artifact install was not confirmed.');
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{timeout: int, input: string, metadata: array<string, string>, cwd?: string, environment?: array<string, string>, bind_application_key?: bool, bind_input?: bool}  $transportOptions
     */
    private function runCliInstallWithSelfUpdateRetry(
        Node $node,
        array $commandOptions,
        array $transportOptions,
    ): ?RemoteShellResult {
        $result = $this->runCliInstallAllowingAgentRestartDisconnect($node, $commandOptions, $transportOptions);

        if (! $result instanceof RemoteShellResult) {
            return null;
        }

        if ($this->shouldRetryCliInstallAfterSelfUpdate($result)) {
            return $this->runCliInstallAllowingAgentRestartDisconnect($node, $commandOptions, $transportOptions);
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{timeout: int, input: string, metadata: array<string, string>, cwd?: string, environment?: array<string, string>, bind_application_key?: bool, bind_input?: bool}  $transportOptions
     */
    private function runCliInstallAllowingAgentRestartDisconnect(
        Node $node,
        array $commandOptions,
        array $transportOptions,
    ): ?RemoteShellResult {
        try {
            return $this->localExecutor->runInternal(
                $node,
                'internal:fleet-update:install-cli',
                [],
                $commandOptions,
                $transportOptions,
            );
        } catch (RemoteLocalExecutorTransportFailed $exception) {
            if (! str_contains($exception->getMessage(), 'Empty reply from server')) {
                throw $exception;
            }

            return null;
        }
    }

    private function shouldRetryCliInstallAfterSelfUpdate(RemoteShellResult $result): bool
    {
        return $result->exitCode === 255 && trim($result->stdout) === '' && trim($result->stderr) === '';
    }
}
