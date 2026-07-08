<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationRun;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Nodes\NodeHostPaths;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RunsInternalCommands;
use RuntimeException;

final readonly class FleetUpdateNodeInstaller
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
        private OperationRunRecorder $operationRuns,
        private FleetUpdateInstallResultInspector $installResults,
        private FleetUpdateLegacyMacosCliPayload $legacyMacosCliPayload,
    ) {}

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{timeout: int, input: string, metadata: array<string, string>, cwd?: string, environment?: array<string, string>, transport?: NodeTransportPreference|string, bind_application_key?: bool, bind_input?: bool, ssh_bootstrap_binary?: array{url: string, sha256: string}, ssh_bootstrap_input_file?: array{path: string, sha256: string}}  $transportOptions
     */
    public function run(
        OperationRun $operationRun,
        Node $node,
        string $eventKey,
        array $commandOptions,
        array $transportOptions,
    ): ?RemoteShellResult {
        $result = $this->runCliInstallAllowingAgentRestartDisconnect($node, $commandOptions, $transportOptions);

        if (! $result instanceof RemoteShellResult) {
            return null;
        }

        if ($this->shouldRetryCliInstallAfterSelfUpdate($result)) {
            $result = $this->runCliInstallAllowingAgentRestartDisconnect($node, $commandOptions, $transportOptions);

            if (! $result instanceof RemoteShellResult) {
                return null;
            }
        }

        if ($this->installResults->shouldRetryAgentInstallAfterCliSelfUpdate($result, $transportOptions['input'])) {
            if (NodeHostPaths::isMacosPlatform($node->platform)) {
                $this->operationRuns->appendStep(
                    $operationRun->id,
                    $eventKey,
                    'running',
                    'Refreshing legacy macOS Orbit CLI path',
                );

                $legacyTransportOptions = $this->legacyMacosCliPayload->transportOptionsFor($transportOptions);
                $bridgeResult = $this->runCliInstallAllowingAgentRestartDisconnect(
                    $node,
                    $this->legacyMacosCliPayload->commandOptionsFor($commandOptions, $legacyTransportOptions),
                    $legacyTransportOptions,
                );

                if (! $bridgeResult instanceof RemoteShellResult) {
                    return null;
                }
            }

            $this->operationRuns->appendStep(
                $operationRun->id,
                $eventKey,
                'running',
                'Installing Orbit Agent artifact',
            );

            $result = $this->runCliInstallAllowingAgentRestartDisconnect($node, $commandOptions, $transportOptions);

            if (! $result instanceof RemoteShellResult) {
                return null;
            }
        }

        if (! $this->installResults->expectedAgentInstallWasConfirmed($result, $transportOptions['input'])) {
            throw new RuntimeException('Orbit Agent artifact install was not confirmed.');
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{timeout: int, input: string, metadata: array<string, string>, cwd?: string, environment?: array<string, string>, transport?: NodeTransportPreference|string, bind_application_key?: bool, bind_input?: bool, ssh_bootstrap_binary?: array{url: string, sha256: string}, ssh_bootstrap_input_file?: array{path: string, sha256: string}}  $transportOptions
     */
    private function runCliInstallAllowingAgentRestartDisconnect(
        Node $node,
        array $commandOptions,
        array $transportOptions,
    ): ?RemoteShellResult {
        try {
            return $this->runCliInstall($node, $commandOptions, $transportOptions);
        } catch (RemoteLocalExecutorTransportFailed $exception) {
            if (! $this->isAgentRestartDisconnect($exception)) {
                throw $exception;
            }

            return null;
        }
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{timeout: int, input: string, metadata: array<string, string>, cwd?: string, environment?: array<string, string>, transport?: NodeTransportPreference|string, bind_application_key?: bool, bind_input?: bool, ssh_bootstrap_binary?: array{url: string, sha256: string}, ssh_bootstrap_input_file?: array{path: string, sha256: string}}  $transportOptions
     */
    private function runCliInstall(Node $node, array $commandOptions, array $transportOptions): RemoteShellResult
    {
        return $this->localExecutor->runInternal(
            $node,
            'internal:fleet-update:install-cli',
            [],
            $commandOptions,
            $transportOptions,
        );
    }

    private function isAgentRestartDisconnect(RemoteLocalExecutorTransportFailed $exception): bool
    {
        return str_contains($exception->getMessage(), 'Empty reply from server');
    }

    private function shouldRetryCliInstallAfterSelfUpdate(RemoteShellResult $result): bool
    {
        return $result->exitCode === 255 && trim($result->stdout) === '' && trim($result->stderr) === '';
    }
}
