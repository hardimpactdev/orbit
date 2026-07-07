<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationRun;
use App\Services\Nodes\NodeHostPaths;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RunsInternalCommands;
use JsonException;
use RuntimeException;

final readonly class FleetUpdateNodeInstaller
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
        private OperationRunRecorder $operationRuns,
        private FleetUpdateInstallResultInspector $installResults,
    ) {}

    /**
     * @param  array{timeout: int, input: string, metadata: array<string, string>}  $transportOptions
     */
    public function run(
        OperationRun $operationRun,
        Node $node,
        string $eventKey,
        array $transportOptions,
    ): ?RemoteShellResult {
        $result = $this->runCliInstallAllowingAgentRestartDisconnect($node, $transportOptions);

        if (! $result instanceof RemoteShellResult) {
            return null;
        }

        if ($this->shouldRetryCliInstallAfterSelfUpdate($result)) {
            $result = $this->runCliInstallAllowingAgentRestartDisconnect($node, $transportOptions);

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

                $bridgeResult = $this->runCliInstallAllowingAgentRestartDisconnect(
                    $node,
                    $this->legacyMacosOrbitBinaryTransportOptions($transportOptions),
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

            $result = $this->runCliInstallAllowingAgentRestartDisconnect($node, $transportOptions);

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
     * @param  array{timeout: int, input: string, metadata: array<string, string>}  $transportOptions
     */
    private function runCliInstallAllowingAgentRestartDisconnect(
        Node $node,
        array $transportOptions,
    ): ?RemoteShellResult {
        try {
            return $this->runCliInstall($node, $transportOptions);
        } catch (RemoteLocalExecutorTransportFailed $exception) {
            if (! $this->isAgentRestartDisconnect($exception)) {
                throw $exception;
            }

            return null;
        }
    }

    /**
     * @param  array{timeout: int, input: string, metadata: array<string, string>}  $transportOptions
     */
    private function runCliInstall(Node $node, array $transportOptions): RemoteShellResult
    {
        return $this->localExecutor->runInternal(
            $node,
            'internal:fleet-update:install-cli',
            [],
            [],
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

    /**
     * @param  array{timeout: int, input: string, metadata: array<string, string>}  $transportOptions
     * @return array{timeout: int, input: string, metadata: array<string, string>}
     *
     * @throws JsonException
     */
    private function legacyMacosOrbitBinaryTransportOptions(array $transportOptions): array
    {
        /** @var mixed $payload */
        $payload = json_decode($transportOptions['input'], associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new RuntimeException('Fleet update CLI install payload must be an object.');
        }

        $payload['bin_path'] = '/usr/local/bin/orbit';
        $payload['shared_binary_path'] = null;

        return [
            ...$transportOptions,
            'input' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];
    }
}
