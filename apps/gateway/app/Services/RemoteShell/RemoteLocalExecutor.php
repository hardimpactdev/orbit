<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Contracts\Loggable;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ActivityLogType;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\ActivityLogger;
use App\Services\NodeCommandTransport\NodeAgentPushDispatcher;
use App\Services\NodeCommandTransport\NodeAgentPushStreamResult;
use App\Services\NodeCommandTransport\NodeCommandEnvelope;
use App\Services\NodeCommandTransport\NodeTransport;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Operations\FleetUpdateNodeCliLauncher;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Database\Eloquent\Model;
use Orbit\Core\Security\OperationTokenCommandContext;
use Orbit\Core\Security\OperationTokenEnvironment;
use RuntimeException;
use Throwable;

final readonly class RemoteLocalExecutor implements RemoteExecutor, RunsInternalCommands
{
    private const string START_UNSUPPORTED_MESSAGE = 'RemoteLocalExecutor::startInternal() is not supported. Long-running local-executor processes are not currently audited; use runInternal() for completion-based dispatch. See apps/docs/content/execution-lanes.md.';

    public function __construct(
        private LocalExecutorCommandComposer $commands,
        private OperationTokenFactory $operationTokens,
        private ActivityLogger $activityLogger,
        private OperationRunRecorder $operationRuns,
        private RemoteExecutorOutputRedactor $outputRedactor,
        private NodeAgentPushDispatcher $agentPush,
        private GatewayLocalCommandDispatcher $gatewayLocal,
        private string $applicationKey,
    ) {
        if (trim($this->applicationKey) === '') {
            throw new RuntimeException('Application key is required.');
        }
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     force_remote_host?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $options
     */
    #[\Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return $this->runInternal(
            node: $node,
            commandName: $script,
            arguments: [],
            commandOptions: [],
            transportOptions: $options,
        );
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     *     bind_application_key?: bool,
     *     bind_input?: bool,
     *     force_remote_host?: bool,
     * }  $transportOptions
     *
     * `transportOptions['environment']` is filtered through the shared
     * operation-token allowlist before minting and Agent envelope dispatch.
     * Arbitrary keys are not passed through into the bound token context.
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $transportOptions = LocalExecutorTransportOptions::fromArray(
            $this->normalizeForceRemoteHostTransportOptions($node, $transportOptions),
        );
        $operationId = $transportOptions->operationId();
        $trustedArgv = $this->commands->buildArgv(
            targetNode: $node,
            commandName: $commandName,
            arguments: $arguments,
            options: $commandOptions,
            operationToken: OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
        );
        $environment = $this->localExecutorEnvironment($node, $transportOptions);
        $activityTransport = $this->intendedActivityTransport($node, $transportOptions);
        $run = $this->operationRuns->queued(
            operationId: $operationId,
            lane: 'local',
            internalCommand: $commandName,
            targetNodeId: $this->nodeId($node),
        );
        $this->operationRuns->running($run->id);
        try {
            $dispatch = $this->dispatchCommand(
                node: $node,
                commandName: $commandName,
                arguments: $arguments,
                commandOptions: $commandOptions,
                operationId: $operationId,
                operationRunId: $run->id,
                trustedArgv: $trustedArgv,
                environment: $environment,
                transportOptions: $transportOptions,
            );
        } catch (Throwable $throwable) {
            $this->operationRuns->failed(
                id: $run->id,
                error: ['code' => 'dispatch_failed', 'class' => $throwable::class],
            );

            throw $throwable;
        }

        try {
            // Record dispatching before selector/execution so selector failures
            // still produce a dispatching/completed pair on the intended lane.
            $this->logDispatching(
                node: $node,
                commandName: $commandName,
                arguments: $arguments,
                commandOptions: $commandOptions,
                operationId: $operationId,
                auditLine: $dispatch['auditLine'],
                transportOptions: $transportOptions,
                activityTransport: $activityTransport,
            );

            $envelope = NodeCommandEnvelope::agentPushBinary(
                operationId: $run->id,
                binary: 'orbit',
                argv: $dispatch['argv'],
                input: $transportOptions->input(),
                cwd: $transportOptions->cwd(),
                environment: $environment,
                timeoutSeconds: $transportOptions->timeoutSeconds(),
            );
            // Selector remains authoritative for the actual execution path.
            $transport = $this->agentPush->select($node, $envelope);
            $forceRemoteHost = $transportOptions->forceRemoteHost();

            $result = match ($transport) {
                // Host-owned gateway checks (systemd, host Caddy, self-route,
                // node-exporter tooling) force the host boundary when requested.
                NodeTransport::GatewayOnly => $this->runGatewayOnly(
                    node: $node,
                    commandName: $commandName,
                    arguments: $arguments,
                    commandOptions: $commandOptions,
                    dispatch: $dispatch,
                    transportOptions: $transportOptions,
                    forceRemoteHost: $forceRemoteHost,
                ),
                NodeTransport::AgentPush => $this->agentPush->execute(
                    node: $node,
                    envelope: $envelope,
                    operationToken: $dispatch['operationToken'],
                ),
            };
        } catch (RemoteShellFailed $exception) {
            $sanitizedResult = $this->outputRedactor->sanitizeResult(
                $exception->result,
                $dispatch['operationToken'],
            );

            $this->operationRuns->failed(
                id: $run->id,
                exitCode: $sanitizedResult->exitCode,
                error: [
                    'code' => 'remote_shell_failed',
                    'duration_ms' => $sanitizedResult->durationMs,
                ],
                stdoutSummary: $this->outputRedactor->summarizeOutput(
                    $sanitizedResult->stdout,
                    $dispatch['operationToken'],
                    $transportOptions->redactStdout(),
                ),
                stderrSummary: $this->outputRedactor->summarizeOutput(
                    $sanitizedResult->stderr,
                    $dispatch['operationToken'],
                    $transportOptions->redactStderr(),
                ),
            );

            $this->logCompleted(
                node: $node,
                commandName: $commandName,
                dispatch: $dispatch,
                result: $sanitizedResult,
                transportOptions: $transportOptions,
                activityTransport: $activityTransport,
            );

            throw new RemoteShellFailed(
                node: $exception->node,
                script: $this->outputRedactor->redactTransportText(
                    value: $exception->script,
                    operationToken: $dispatch['operationToken'],
                ),
                result: $sanitizedResult,
            );
        } catch (Throwable $throwable) {
            $redactedMessage = $this->outputRedactor->exceptionMessageSummary(
                throwable: $throwable,
                operationToken: $dispatch['operationToken'],
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            );
            $redactedMetadata = $this->outputRedactor->exceptionMetadata(
                throwable: $throwable,
                operationToken: $dispatch['operationToken'],
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            );

            $this->operationRuns->failed(
                id: $run->id,
                error: [
                    'code' => 'transport_failed',
                    'class' => $throwable::class,
                    'message' => $redactedMessage,
                ],
            );

            $this->logTransportException(
                node: $node,
                commandName: $commandName,
                operationId: $operationId,
                throwable: $throwable,
                exceptionMessage: $redactedMessage,
                activityTransport: $activityTransport,
            );

            throw new RemoteLocalExecutorTransportFailed(
                message: "Remote local executor transport failed: {$redactedMessage}",
                meta: $redactedMetadata,
                code: (int) $throwable->getCode(),
            );
        }

        $this->operationRuns->succeeded(
            id: $run->id,
            exitCode: $result->exitCode,
            stdoutSummary: $this->outputRedactor->summarizeOutput(
                $result->stdout,
                $dispatch['operationToken'],
                $transportOptions->redactStdout(),
            ),
            stderrSummary: $this->outputRedactor->summarizeOutput(
                $result->stderr,
                $dispatch['operationToken'],
                $transportOptions->redactStderr(),
            ),
        );

        $this->logCompleted(
            node: $node,
            commandName: $commandName,
            dispatch: $dispatch,
            result: $result,
            transportOptions: $transportOptions,
            activityTransport: $activityTransport,
        );

        return $result;
    }

    /**
     * @param  callable(string): void  $onOutput
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     *     bind_application_key?: bool,
     *     bind_input?: bool,
     *     force_remote_host?: bool,
     * }  $transportOptions
     */
    public function streamInternal(
        Node $node,
        string $commandName,
        callable $onOutput,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): void {
        $transportOptions = LocalExecutorTransportOptions::fromArray(
            $this->normalizeForceRemoteHostTransportOptions($node, $transportOptions),
        );
        $operationId = $transportOptions->operationId();
        $trustedArgv = $this->commands->buildArgv(
            targetNode: $node,
            commandName: $commandName,
            arguments: $arguments,
            options: $commandOptions,
            operationToken: OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
        );
        $environment = $this->localExecutorEnvironment($node, $transportOptions);
        $run = $this->operationRuns->queued(
            operationId: $operationId,
            lane: 'local',
            internalCommand: $commandName,
            targetNodeId: $this->nodeId($node),
        );
        $this->operationRuns->running($run->id);
        try {
            $dispatch = $this->dispatchCommand(
                node: $node,
                commandName: $commandName,
                arguments: $arguments,
                commandOptions: $commandOptions,
                operationId: $operationId,
                operationRunId: $run->id,
                trustedArgv: $trustedArgv,
                environment: $environment,
                transportOptions: $transportOptions,
            );
        } catch (Throwable $throwable) {
            $this->operationRuns->failed(
                id: $run->id,
                error: ['code' => 'dispatch_failed', 'class' => $throwable::class],
            );

            throw $throwable;
        }
        $startedAt = $this->monotonicNanoseconds();

        $activityTransport = 'agent_push';

        try {
            $this->logDispatching(
                node: $node,
                commandName: $commandName,
                arguments: $arguments,
                commandOptions: $commandOptions,
                operationId: $operationId,
                auditLine: $dispatch['auditLine'],
                transportOptions: $transportOptions,
                activityTransport: $activityTransport,
            );

            $streamResult = $this->agentPush->stream(
                node: $node,
                envelope: NodeCommandEnvelope::agentPushBinary(
                    operationId: $run->id,
                    binary: 'orbit',
                    argv: $dispatch['argv'],
                    input: $transportOptions->input(),
                    cwd: $transportOptions->cwd(),
                    environment: $environment,
                    timeoutSeconds: $transportOptions->streamTimeoutSeconds(),
                    stream: true,
                ),
                operationToken: $dispatch['operationToken'],
                onOutput: $onOutput,
            );

            $result = new RemoteShellResult(
                exitCode: $this->streamExitCode($streamResult),
                stdout: '',
                stderr: '',
                durationMs: $this->elapsedMilliseconds($startedAt),
            );

            if ($streamResult->failed()) {
                $this->operationRuns->failed(
                    id: $run->id,
                    exitCode: $streamResult->exitCode,
                    error: $this->streamFailureError($streamResult, $result),
                );

                $this->logCompleted(
                    node: $node,
                    commandName: $commandName,
                    dispatch: $dispatch,
                    result: $result,
                    transportOptions: $transportOptions,
                    activityTransport: $activityTransport,
                );

                throw new RemoteShellFailed(
                    node: $node,
                    script: $dispatch['auditLine'],
                    result: $result,
                );
            }
        } catch (Throwable $throwable) {
            if ($throwable instanceof RemoteShellFailed) {
                throw $throwable;
            }
            $redactedMessage = $this->outputRedactor->exceptionMessageSummary(
                throwable: $throwable,
                operationToken: $dispatch['operationToken'],
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            );
            $redactedMetadata = $this->outputRedactor->exceptionMetadata(
                throwable: $throwable,
                operationToken: $dispatch['operationToken'],
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            );

            $this->operationRuns->failed(
                id: $run->id,
                error: [
                    'code' => 'transport_failed',
                    'class' => $throwable::class,
                    'message' => $redactedMessage,
                ],
            );

            $this->logTransportException(
                node: $node,
                commandName: $commandName,
                operationId: $operationId,
                throwable: $throwable,
                exceptionMessage: $redactedMessage,
                activityTransport: $activityTransport,
            );

            throw new RemoteLocalExecutorTransportFailed(
                message: "Remote local executor transport failed: {$redactedMessage}",
                meta: $redactedMetadata,
                code: (int) $throwable->getCode(),
            );
        }

        $result = new RemoteShellResult(
            exitCode: $streamResult->exitCode ?? 0,
            stdout: '',
            stderr: '',
            durationMs: $this->elapsedMilliseconds($startedAt),
        );

        $this->operationRuns->succeeded(id: $run->id, exitCode: $streamResult->exitCode ?? 0);
        $this->logCompleted(
            node: $node,
            commandName: $commandName,
            dispatch: $dispatch,
            result: $result,
            transportOptions: $transportOptions,
            activityTransport: $activityTransport,
        );
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     force_remote_host?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     * }  $options
     */
    #[\Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException(self::START_UNSUPPORTED_MESSAGE);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     *     bind_application_key?: bool,
     *     bind_input?: bool,
     *     force_remote_host?: bool,
     * }  $transportOptions
     */
    public function startInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): InvokedProcess {
        throw new RuntimeException(self::START_UNSUPPORTED_MESSAGE);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  list<string>  $trustedArgv
     * @param  array<string, string>  $environment
     * @return array{
     *     operationId: string,
     *     operationToken: string,
     *     auditLine: string,
     *     argv: list<string>,
     *     commandContext: OperationTokenCommandContext,
     * }
     *
     * @mago-expect lint:excessive-parameter-list
     */
    private function dispatchCommand(
        Node $node,
        string $commandName,
        array $arguments,
        array $commandOptions,
        string $operationId,
        string $operationRunId,
        array $trustedArgv,
        array $environment,
        LocalExecutorTransportOptions $transportOptions,
    ): array {
        $commandContext = OperationTokenCommandContext::fromTrustedDispatch(
            argv: $trustedArgv,
            cwd: $transportOptions->cwd(),
            environment: $environment,
            input: $transportOptions->boundInput(),
        );
        $operationToken = $this->operationTokens
            ->mint(
                operationId: $operationRunId,
                targetNode: $node->name,
                command: $commandName,
                commandContext: $commandContext,
            )
            ->toString();

        return [
            'operationId' => $operationId,
            'operationToken' => $operationToken,
            'commandContext' => $commandContext,
            'argv' => $this->argvWithOperationToken($trustedArgv, $operationToken),
            'auditLine' => $this->commands->buildAuditLine(
                targetNode: $node,
                commandName: $commandName,
                arguments: $arguments,
                options: $commandOptions,
                operationToken: $operationToken,
            ),
        ];
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function argvWithOperationToken(array $argv, string $operationToken): array
    {
        return array_map(
            static fn (string $argument): string => str_replace(
                OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
                $operationToken,
                $argument,
            ),
            $argv,
        );
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @mago-expect lint:excessive-parameter-list
     */
    private function logDispatching(
        Node $node,
        string $commandName,
        array $arguments,
        array $commandOptions,
        string $operationId,
        string $auditLine,
        LocalExecutorTransportOptions $transportOptions,
        string $activityTransport,
    ): void {
        // ActivityLogger is the global no-secrets property boundary. Keep only
        // explicit redact_command_options scrubbing here so caller-named option
        // values are removed from the audit line and structured options before
        // that boundary (literal values not always inferable from key shape).
        $explicitOptionNames = $transportOptions->redactedCommandOptionNames();
        $commandLine = $this->outputRedactor->redactCommandOptionsInLine($auditLine, $transportOptions);

        $this->activityLogger->log(
            new LocalExecutorActivity(
                event: "{$activityTransport}.dispatching",
                subject: $node,
                description: $this->activityDescription($activityTransport, 'dispatching'),
                properties: [
                    'lane' => 'internal',
                    'transport' => $activityTransport,
                    'status' => 'dispatching',
                    'operation_id' => $operationId,
                    'target_node_id' => $this->nodeId($node),
                    'target_node_name' => $node->name,
                    'command' => $commandName,
                    'arguments' => $this->scalarPayload($arguments),
                    'command_options' => $this->scalarPayload($commandOptions, $explicitOptionNames),
                    'command_line' => $commandLine,
                ],
            ),
            channel: 'api',
            causer: null,
        );
    }

    /**
     * @param  array{
     *     operationId: string,
     *     operationToken: string,
     *     auditLine: string,
     *     argv: list<string>,
     *     commandContext: OperationTokenCommandContext,
     * }  $dispatch
     *
     * @mago-expect lint:excessive-parameter-list
     */
    private function logCompleted(
        Node $node,
        string $commandName,
        array $dispatch,
        RemoteShellResult $result,
        LocalExecutorTransportOptions $transportOptions,
        string $activityTransport,
    ): void {
        $status = $result->successful() ? 'succeeded' : 'failed';

        $this->activityLogger->log(
            new LocalExecutorActivity(
                event: "{$activityTransport}.completed",
                subject: $node,
                description: $this->activityDescription($activityTransport, $status),
                properties: [
                    'lane' => 'internal',
                    'transport' => $activityTransport,
                    'status' => $status,
                    'operation_id' => $dispatch['operationId'],
                    'target_node_id' => $this->nodeId($node),
                    'target_node_name' => $node->name,
                    'command' => $commandName,
                    'exit_code' => $result->exitCode,
                    'stdout_summary' => $this->outputRedactor->summarizeOutput(
                        $result->stdout,
                        $dispatch['operationToken'],
                        $transportOptions->redactStdout(),
                    ),
                    'stderr_summary' => $this->outputRedactor->summarizeOutput(
                        $result->stderr,
                        $dispatch['operationToken'],
                        $transportOptions->redactStderr(),
                    ),
                    'duration_ms' => $result->durationMs,
                ],
            ),
            channel: 'api',
            causer: null,
        );
    }

    /**
     * @mago-expect lint:excessive-parameter-list
     */
    private function logTransportException(
        Node $node,
        string $commandName,
        string $operationId,
        Throwable $throwable,
        string $exceptionMessage,
        string $activityTransport,
    ): void {
        $this->activityLogger->log(
            new LocalExecutorActivity(
                event: "{$activityTransport}.completed",
                subject: $node,
                description: $this->activityDescription($activityTransport, 'failed'),
                properties: [
                    'lane' => 'internal',
                    'transport' => $activityTransport,
                    'status' => 'failed',
                    'operation_id' => $operationId,
                    'target_node_id' => $this->nodeId($node),
                    'target_node_name' => $node->name,
                    'command' => $commandName,
                    'exit_code' => null,
                    'stdout_summary' => '',
                    'stderr_summary' => '',
                    'exception_class' => $throwable::class,
                    'exception_message' => $exceptionMessage,
                ],
            ),
            channel: 'api',
            causer: null,
        );
    }

    /**
     * Deterministic audit-lane label for activity records.
     *
     * Derived from gateway role + already-normalized force_remote_host before
     * envelope build/selection. Selector stays authoritative for execution;
     * this only labels the RemoteLocalExecutor dispatching/completed pair.
     *
     */
    private function intendedActivityTransport(Node $node, LocalExecutorTransportOptions $transportOptions): string
    {
        if ($node->hasActiveRole('gateway')) {
            return $transportOptions->forceRemoteHost()
                ? 'force_remote_host'
                : 'gateway_local';
        }

        return 'agent_push';
    }

    private function activityDescription(string $activityTransport, string $status): string
    {
        $label = match ($activityTransport) {
            'gateway_local' => 'Gateway local',
            'force_remote_host' => 'Force remote host',
            default => 'Agent push',
        };

        if ($status === 'dispatching') {
            return "{$label} operation dispatching";
        }

        return "{$label} operation {$status}";
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round(($this->monotonicNanoseconds() - $startedAt) / 1_000_000));
    }

    private function monotonicNanoseconds(): int
    {
        $now = hrtime(true);

        if (! is_int($now)) {
            throw new RuntimeException('Could not read monotonic clock.');
        }

        return $now;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  list<string>  $redactedKeys
     * @return array<int|string, bool|float|int|string>
     */
    private function scalarPayload(array $values, array $redactedKeys = []): array
    {
        $payload = [];

        foreach ($values as $key => $value) {
            if (is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
                $payload[$key] = is_string($key) && in_array($key, $redactedKeys, true)
                    ? RemoteExecutorOutputRedactor::REDACTED_VALUE
                    : $value;
            }
        }

        return $payload;
    }

    /**
     * @return array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     force_remote_host?: bool,
     * }
     */
    private function transportDispatchOptions(
        Node $node,
        LocalExecutorTransportOptions $transportOptions,
    ): array {
        $environment = $this->localExecutorEnvironment($node, $transportOptions);

        return $transportOptions->dispatchOptions($environment);
    }

    /**
     * @return array<string, string>
     */
    private function localExecutorEnvironment(
        Node $node,
        LocalExecutorTransportOptions $transportOptions,
    ): array {
        $environment = $transportOptions->environment();
        $home = $environment['HOME'] ?? $this->defaultLocalExecutorHome($node);
        $forceRemoteHost = $transportOptions->forceRemoteHost();

        // force_remote_host has one canonical context: HOME + host-home cwd only.
        // Drop caller-supplied allowlisted keys so mint matches the host CLI after
        // the remote script unsets optional profile exports.
        if ($forceRemoteHost) {
            return OperationTokenEnvironment::allowlisted([
                'HOME' => $home,
            ]);
        }

        $environment['HOME'] = $home;
        $environment['ORBIT_CONFIG_PATH'] ??= "{$home}/.config/orbit/config.json";

        if (! $node->hasActiveRole('gateway')) {
            $environment['ORBIT_BIN_PATH'] = FleetUpdateNodeCliLauncher::binPath($node);
        }

        if ($transportOptions->shouldBindApplicationKey()) {
            $environment['APP_KEY'] = $this->applicationKey;
        } else {
            unset($environment['APP_KEY']);
        }

        // Token-bound and Agent-envelope environment share the same allowlist so
        // mint hash matches CLI/Agent verification reconstruction.
        return OperationTokenEnvironment::allowlisted($environment);
    }

    /**
     * force_remote_host leaves the gateway container over SSH. The host CLI is the
     * sole token consumer and rebuilds verification context from real remote
     * getcwd()/environment. Normalize mint inputs so they match that payload:
     * explicit host-home cwd (composed script cds there) and no APP_KEY bind.
     *
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     *     bind_application_key?: bool,
     *     bind_input?: bool,
     *     force_remote_host?: bool,
     * }  $transportOptions
     * @return array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment?: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     *     bind_application_key?: bool,
     *     bind_input?: bool,
     *     force_remote_host?: bool,
     * }
     */
    private function normalizeForceRemoteHostTransportOptions(Node $node, array $transportOptions): array
    {
        if (($transportOptions['force_remote_host'] ?? false) !== true) {
            return $transportOptions;
        }

        // force_remote_host is only meaningful for the gateway host boundary.
        // Agent-push targets must mint ordinary token context; host-boundary
        // normalization (cwd/APP_KEY) makes the Agent verifier reject tokens.
        if (! GatewayHostExecution::shouldForceRemoteHostFor($node)) {
            unset($transportOptions['force_remote_host']);

            return $transportOptions;
        }

        if (! array_key_exists('cwd', $transportOptions)) {
            $transportOptions['cwd'] = $this->defaultLocalExecutorHome($node);
        }

        // Never place APP_KEY into force_remote_host token context. RemoteHostExecutor
        // Process::env does not export to the remote shell, and secrets must not ride
        // the SSH command line either.
        $transportOptions['bind_application_key'] = false;

        return $transportOptions;
    }

    private function defaultLocalExecutorHome(Node $node): string
    {
        return NodeHostPaths::homeDirectoryFor($node->platform, $node->user);
    }

    /**
     * Canonical remote-host execution script: host-installed orbit binary, host home,
     * and only the HOME env binding that OperationTokenGuard will observe after
     * unsetting optional allowlisted keys the host profile may export.
     *
     * @param  array{
     *     operationId: string,
     *     operationToken: string,
     *     auditLine: string,
     *     argv: list<string>,
     *     commandContext: OperationTokenCommandContext,
     * }  $dispatch
     * @param  array<string, mixed>  $dispatchOptions
     */
    private function forceRemoteHostScript(Node $node, array $dispatch, array $dispatchOptions): string
    {
        $home = is_string($dispatchOptions['cwd'] ?? null) && $dispatchOptions['cwd'] !== ''
            ? $dispatchOptions['cwd']
            : $this->defaultLocalExecutorHome($node);
        $hostBinary = FleetUpdateNodeCliLauncher::binPath($node);
        $argv = array_map(
            escapeshellarg(...),
            $dispatch['argv'],
        );

        // Keep remote process env aligned with the minted host-boundary context:
        // cwd is applied by RemoteShellScriptComposer; HOME is the only allowlisted
        // token-bound key; never export APP_KEY or config paths over SSH.
        return implode(' ', [
            'export',
            'HOME='.escapeshellarg($home).';',
            'unset',
            ...OperationTokenEnvironment::forceRemoteHostUnsetKeys(),
            ';',
            'exec',
            escapeshellarg($hostBinary),
            ...$argv,
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{
     *     operationId: string,
     *     operationToken: string,
     *     auditLine: string,
     *     argv: list<string>,
     *     commandContext: OperationTokenCommandContext,
     * }  $dispatch
     * @mago-expect lint:excessive-parameter-list
     */
    private function runGatewayOnly(
        Node $node,
        string $commandName,
        array $arguments,
        array $commandOptions,
        array $dispatch,
        LocalExecutorTransportOptions $transportOptions,
        bool $forceRemoteHost = false,
    ): RemoteShellResult {
        $dispatchOptions = $this->transportDispatchOptions($node, $transportOptions);

        if ($forceRemoteHost) {
            $dispatchOptions['force_remote_host'] = true;

            // Host-owned gateway doctor checks leave the orbit-gateway container via
            // the host substrate executor (same lane as SshRemoteShell host work).
            // The host CLI is the sole operation-token verifier/consumer; do not
            // pre-authorize here or the one-use token is already spent on the host.
            // @orbit-ssh-lane provisioning-ssh
            return app(RemoteHostExecutor::class)->run(
                node: $node,
                script: $this->forceRemoteHostScript($node, $dispatch, $dispatchOptions),
                options: $dispatchOptions,
            );
        }

        $script = $this->commands->build(
            targetNode: $node,
            commandName: $commandName,
            arguments: $arguments,
            options: $commandOptions,
            operationToken: $dispatch['operationToken'],
        );

        return $this->gatewayLocal->run(
            node: $node,
            commandName: $commandName,
            script: $script,
            dispatch: $dispatch,
            dispatchOptions: $dispatchOptions,
        );
    }

    private function streamExitCode(NodeAgentPushStreamResult $streamResult): int
    {
        if ($streamResult->agentError !== null) {
            return 1;
        }

        return $streamResult->exitCode ?? 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function streamFailureError(NodeAgentPushStreamResult $streamResult, RemoteShellResult $result): array
    {
        if ($streamResult->agentError !== null) {
            return [
                'code' => 'agent_push_stream_failed',
                'agent_error' => $streamResult->agentError,
            ];
        }

        return [
            'code' => 'remote_shell_failed',
            'duration_ms' => $result->durationMs,
        ];
    }

    private function nodeId(Node $node): int
    {
        return $node->id;
    }
}

final readonly class LocalExecutorActivity implements Loggable
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        private string $event,
        private Node $subject,
        private string $description,
        private array $properties,
    ) {}

    #[\Override]
    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    #[\Override]
    public function type(): string
    {
        return $this->event;
    }

    #[\Override]
    public function subject(): Model
    {
        return $this->subject;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function properties(): array
    {
        return $this->properties;
    }

    #[\Override]
    public function description(): string
    {
        return $this->description;
    }
}
