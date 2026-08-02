<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Contracts\Loggable;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ActivityLogType;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\ActivityLogger;
use App\Services\NodeCommandTransport\NodeAgentPushClient;
use App\Services\NodeCommandTransport\NodeAgentPushResult;
use App\Services\NodeCommandTransport\NodeAgentPushStreamResult;
use App\Services\NodeCommandTransport\NodeCommandEnvelope;
use App\Services\NodeCommandTransport\NodeCommandTransportSelector;
use App\Services\NodeCommandTransport\NodeTransport;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Operations\FleetUpdateNodeCliLauncher;
use App\Services\Operations\GatewayLocalOperationTokenAuthorizer;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Orbit\Core\Security\OperationTokenCommandContext;
use RuntimeException;
use Throwable;

final readonly class RemoteLocalExecutor implements RemoteExecutor, RunsInternalCommands
{
    private const string OPERATION_ID_METADATA_KEY = 'ORBIT_OPERATION_ID';

    private const int OUTPUT_SUMMARY_BYTES = 4_096;

    private const string TRUNCATED_SUFFIX = '[truncated]';

    private const string SUPPRESSED_OUTPUT_SUMMARY = '<suppressed>';

    private const string REDACTED_VALUE = '<redacted>';

    private const string COMMAND_OPTION_KEY_PATTERN = '/\A[a-z][a-z0-9-]*\z/';

    private const string ENVIRONMENT_KEY_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    private const string BIND_APPLICATION_KEY_OPTION = 'bind_application_key';

    private const string BIND_INPUT_OPTION = 'bind_input';

    private const string START_UNSUPPORTED_MESSAGE = 'RemoteLocalExecutor::startInternal() is not supported. Long-running local-executor processes are not currently audited; use runInternal() for completion-based dispatch. See apps/docs/content/execution-lanes.md.';

    public function __construct(
        private LocalExecutorCommandComposer $commands,
        private OperationTokenFactory $operationTokens,
        private ActivityLogger $activityLogger,
        private OperationRunRecorder $operationRuns,
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
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $transportOptions = $this->normalizeForceRemoteHostTransportOptions($node, $transportOptions);
        $operationId = $this->operationId($transportOptions);
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

        try {
            $this->logDispatching(
                node: $node,
                commandName: $commandName,
                arguments: $arguments,
                commandOptions: $commandOptions,
                operationId: $operationId,
                auditLine: $dispatch['auditLine'],
                transportOptions: $transportOptions,
            );

            $envelope = NodeCommandEnvelope::agentPushBinary(
                operationId: $run->id,
                binary: 'orbit',
                argv: $dispatch['argv'],
                input: $this->input($transportOptions),
                cwd: $this->cwd($transportOptions),
                environment: $this->localExecutorEnvironment($node, $transportOptions),
                timeoutSeconds: $this->timeoutSeconds($transportOptions),
            );
            $transport = app(NodeCommandTransportSelector::class)->select($node, $envelope);
            $forceRemoteHost = ($transportOptions['force_remote_host'] ?? false) === true;
            $result = match ($transport) {
                // Host-owned gateway checks (systemd, host Caddy, self-route,
                // node-exporter tooling) force the host boundary when requested.
                NodeTransport::GatewayOnly => $this->runGatewayLocal(
                    node: $node,
                    commandName: $commandName,
                    arguments: $arguments,
                    commandOptions: $commandOptions,
                    dispatch: $dispatch,
                    transportOptions: $transportOptions,
                    forceRemoteHost: $forceRemoteHost,
                ),
                NodeTransport::AgentPush => $this->runAgentPush(
                    node: $node,
                    dispatch: $dispatch,
                    envelope: $envelope,
                ),
            };
        } catch (RemoteShellFailed $exception) {
            $sanitizedResult = $this->sanitizedResult($exception->result, $dispatch['operationToken']);

            $this->operationRuns->failed(
                id: $run->id,
                exitCode: $sanitizedResult->exitCode,
                error: [
                    'code' => 'remote_shell_failed',
                    'duration_ms' => $sanitizedResult->durationMs,
                ],
                stdoutSummary: $this->outputSummary(
                    $sanitizedResult->stdout,
                    $dispatch['operationToken'],
                    $transportOptions['redact_stdout'] ?? false,
                ),
                stderrSummary: $this->outputSummary(
                    $sanitizedResult->stderr,
                    $dispatch['operationToken'],
                    $transportOptions['redact_stderr'] ?? false,
                ),
            );

            $this->logCompleted(
                node: $node,
                commandName: $commandName,
                dispatch: $dispatch,
                result: $sanitizedResult,
                transportOptions: $transportOptions,
            );

            throw new RemoteShellFailed(
                node: $exception->node,
                script: $this->redactTransportSecrets(
                    value: $exception->script,
                    operationToken: $dispatch['operationToken'],
                    transportOptions: $transportOptions,
                ),
                result: $sanitizedResult,
            );
        } catch (Throwable $throwable) {
            $redactedMessage = $this->transportExceptionMessageSummary(
                throwable: $throwable,
                operationToken: $dispatch['operationToken'],
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            );
            $redactedMetadata = $this->transportExceptionMetadata(
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
            stdoutSummary: $this->outputSummary(
                $result->stdout,
                $dispatch['operationToken'],
                $transportOptions['redact_stdout'] ?? false,
            ),
            stderrSummary: $this->outputSummary(
                $result->stderr,
                $dispatch['operationToken'],
                $transportOptions['redact_stderr'] ?? false,
            ),
        );

        $this->logCompleted(
            node: $node,
            commandName: $commandName,
            dispatch: $dispatch,
            result: $result,
            transportOptions: $transportOptions,
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
        $transportOptions = $this->normalizeForceRemoteHostTransportOptions($node, $transportOptions);
        $operationId = $this->operationId($transportOptions);
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

        try {
            $this->logDispatching(
                node: $node,
                commandName: $commandName,
                arguments: $arguments,
                commandOptions: $commandOptions,
                operationId: $operationId,
                auditLine: $dispatch['auditLine'],
                transportOptions: $transportOptions,
            );

            $streamResult = $this->streamAgentPush(
                node: $node,
                dispatch: $dispatch,
                operationId: $run->id,
                transportOptions: $transportOptions,
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
            $redactedMessage = $this->transportExceptionMessageSummary(
                throwable: $throwable,
                operationToken: $dispatch['operationToken'],
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            );
            $redactedMetadata = $this->transportExceptionMetadata(
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
     * @param  array<string, mixed>  $transportOptions
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
        array $transportOptions,
    ): array {
        $commandContext = OperationTokenCommandContext::fromTrustedDispatch(
            argv: $trustedArgv,
            cwd: $this->cwd($transportOptions),
            environment: $environment,
            input: $this->boundInput($transportOptions),
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
    private function logDispatching(
        Node $node,
        string $commandName,
        array $arguments,
        array $commandOptions,
        string $operationId,
        string $auditLine,
        array $transportOptions,
    ): void {
        $redactedCommandOptionNames = $this->redactedCommandOptionNames($transportOptions);

        $this->activityLogger->log(
            new LocalExecutorActivity(
                event: 'agent_push.dispatching',
                subject: $node,
                description: 'Agent push operation dispatching',
                properties: [
                    'lane' => 'internal',
                    'transport' => 'agent_push',
                    'status' => 'dispatching',
                    'operation_id' => $operationId,
                    'target_node_id' => $this->nodeId($node),
                    'target_node_name' => $node->name,
                    'command' => $commandName,
                    'arguments' => $this->scalarPayload($arguments),
                    'command_options' => $this->scalarPayload($commandOptions, $redactedCommandOptionNames),
                    'command_line' => $this->redactCommandOptionsInLine($auditLine, $redactedCommandOptionNames),
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
     */
    private function logCompleted(
        Node $node,
        string $commandName,
        array $dispatch,
        RemoteShellResult $result,
        array $transportOptions,
    ): void {
        $status = $result->successful() ? 'succeeded' : 'failed';

        $this->activityLogger->log(
            new LocalExecutorActivity(
                event: 'agent_push.completed',
                subject: $node,
                description: "Agent push operation {$status}",
                properties: [
                    'lane' => 'internal',
                    'transport' => 'agent_push',
                    'status' => $status,
                    'operation_id' => $dispatch['operationId'],
                    'target_node_id' => $this->nodeId($node),
                    'target_node_name' => $node->name,
                    'command' => $commandName,
                    'exit_code' => $result->exitCode,
                    'stdout_summary' => $this->outputSummary(
                        $result->stdout,
                        $dispatch['operationToken'],
                        (bool) ($transportOptions['redact_stdout'] ?? false),
                    ),
                    'stderr_summary' => $this->outputSummary(
                        $result->stderr,
                        $dispatch['operationToken'],
                        (bool) ($transportOptions['redact_stderr'] ?? false),
                    ),
                    'duration_ms' => $result->durationMs,
                ],
            ),
            channel: 'api',
            causer: null,
        );
    }

    private function logTransportException(
        Node $node,
        string $commandName,
        string $operationId,
        Throwable $throwable,
        string $exceptionMessage,
    ): void {
        $this->activityLogger->log(
            new LocalExecutorActivity(
                event: 'agent_push.completed',
                subject: $node,
                description: 'Agent push operation failed',
                properties: [
                    'lane' => 'internal',
                    'transport' => 'agent_push',
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

    private function sanitizedResult(RemoteShellResult $result, string $operationToken): RemoteShellResult
    {
        return new RemoteShellResult(
            exitCode: $result->exitCode,
            stdout: $this->redactOperationToken($result->stdout, $operationToken),
            stderr: $this->redactOperationToken($result->stderr, $operationToken),
            durationMs: $result->durationMs,
        );
    }

    private function outputSummary(string $output, string $operationToken, bool $suppress = false): string
    {
        if ($suppress) {
            return self::SUPPRESSED_OUTPUT_SUMMARY;
        }

        return $this->truncate($this->redactOperationToken($output, $operationToken));
    }

    /**
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
    private function transportExceptionMessageSummary(
        Throwable $throwable,
        string $operationToken,
        array $transportOptions,
        array $commandOptions,
    ): string {
        return $this->outputSummary(
            output: $this->redactExceptionText(
                value: $throwable->getMessage(),
                operationToken: $operationToken,
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            ),
            operationToken: $operationToken,
            suppress: $this->shouldSuppressExceptionMessage($transportOptions),
        );
    }

    /**
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
     * @return array<array-key, mixed>
     */
    private function transportExceptionMetadata(
        Throwable $throwable,
        string $operationToken,
        array $transportOptions,
        array $commandOptions,
    ): array {
        $metadata = $this->rawTransportExceptionMetadata($throwable);

        if ($metadata === []) {
            return [];
        }

        return $this->redactExceptionMetadata(
            metadata: $metadata,
            operationToken: $operationToken,
            transportOptions: $transportOptions,
            commandOptions: $commandOptions,
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
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     *     bind_application_key?: bool,
     *     bind_input?: bool,
     *     force_remote_host?: bool,
     * }  $transportOptions
     */
    private function shouldSuppressExceptionMessage(array $transportOptions): bool
    {
        return ($transportOptions['redact_stdout'] ?? false) || ($transportOptions['redact_stderr'] ?? false);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function rawTransportExceptionMetadata(Throwable $throwable): array
    {
        $reflection = new \ReflectionObject($throwable);

        if (! $reflection->hasProperty('meta')) {
            return [];
        }

        $property = $reflection->getProperty('meta');

        if (! $property->isPublic()) {
            return [];
        }

        $metadata = $property->getValue($throwable);

        return is_array($metadata) ? $metadata : [];
    }

    private function redactOperationToken(string $value, string $operationToken): string
    {
        $redacted =
            preg_replace(
                '/--operation-token\s*(?:=\s*|\s+)(?:"[^"]*"|\'[^\']*\'|\S+)/',
                '--operation-token='.self::REDACTED_VALUE,
                $value,
            ) ?? $value;

        if ($operationToken === '') {
            return $redacted;
        }

        return str_replace($operationToken, self::REDACTED_VALUE, $redacted);
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     */
    private function redactTransportSecrets(
        string $value,
        string $operationToken,
        array $transportOptions,
    ): string {
        return $this->redactOperationToken($value, $operationToken);
    }

    /**
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
    private function redactExceptionText(
        string $value,
        string $operationToken,
        array $transportOptions,
        array $commandOptions,
    ): string {
        return $this->redactTransportSecrets(
            value: $this->redactCommandOptionSecrets($value, $transportOptions, $commandOptions),
            operationToken: $operationToken,
            transportOptions: $transportOptions,
        );
    }

    /**
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
    private function redactCommandOptionSecrets(string $value, array $transportOptions, array $commandOptions): string
    {
        $optionNames = $this->redactedCommandOptionNames($transportOptions);

        if ($optionNames === []) {
            return $value;
        }

        $redacted = $this->redactCommandOptionsInLine($value, $optionNames);

        foreach ($this->redactedCommandOptionValues($commandOptions, $optionNames) as $optionValue) {
            $redacted = str_replace($optionValue, self::REDACTED_VALUE, $redacted);
        }

        return $redacted;
    }

    /**
     * @param  array<array-key, mixed>  $metadata
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
     * @return array<array-key, mixed>
     */
    private function redactExceptionMetadata(
        array $metadata,
        string $operationToken,
        array $transportOptions,
        array $commandOptions,
    ): array {
        $optionNames = $this->redactedCommandOptionNames($transportOptions);
        $redacted = [];

        foreach ($metadata as $key => $value) {
            if (in_array($key, $optionNames, true)) {
                $redacted[$key] = self::REDACTED_VALUE;

                continue;
            }

            $redacted[$key] = $this->redactExceptionMetadataValue(
                value: $value,
                operationToken: $operationToken,
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            );
        }

        return $redacted;
    }

    /**
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
    private function redactExceptionMetadataValue(
        mixed $value,
        string $operationToken,
        array $transportOptions,
        array $commandOptions,
    ): mixed {
        if (is_string($value)) {
            return $this->redactExceptionText($value, $operationToken, $transportOptions, $commandOptions);
        }

        if (is_array($value)) {
            return $this->redactExceptionMetadata($value, $operationToken, $transportOptions, $commandOptions);
        }

        if (is_bool($value) || is_float($value) || is_int($value) || $value === null) {
            return $value;
        }

        return self::REDACTED_VALUE;
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  list<string>  $optionNames
     * @return list<string>
     */
    private function redactedCommandOptionValues(array $commandOptions, array $optionNames): array
    {
        $values = [];

        foreach ($optionNames as $optionName) {
            $optionValue = $commandOptions[$optionName] ?? null;

            if (! is_string($optionValue) || $optionValue === '') {
                continue;
            }

            if (! in_array($optionValue, $values, true)) {
                $values[] = $optionValue;
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $optionNames
     */
    private function redactCommandOptionsInLine(string $value, array $optionNames): string
    {
        $redacted = $value;

        foreach ($optionNames as $optionName) {
            $redacted =
                preg_replace(
                    '/--'.preg_quote($optionName, '/').'\s*(?:=\s*|\s+)(?:"[^"]*"|\'[^\']*\'|\S+)/',
                    "--{$optionName}=".self::REDACTED_VALUE,
                    $redacted,
                ) ?? $redacted;
        }

        return $redacted;
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= self::OUTPUT_SUMMARY_BYTES) {
            return $value;
        }

        return substr($value, 0, self::OUTPUT_SUMMARY_BYTES).self::TRUNCATED_SUFFIX;
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
                    ? self::REDACTED_VALUE
                    : $value;
            }
        }

        return $payload;
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
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     *     bind_application_key?: bool,
     *     bind_input?: bool,
     *     force_remote_host?: bool,
     * }  $transportOptions
     * @return list<string>
     */
    private function redactedCommandOptionNames(array $transportOptions): array
    {
        $optionNames = $transportOptions['redact_command_options'] ?? [];

        if ($optionNames === []) {
            return [];
        }

        if (! is_array($optionNames) || ! array_is_list($optionNames)) {
            throw new RuntimeException('redact_command_options must be a list of command option names.');
        }

        $redacted = [];

        foreach ($optionNames as $optionName) {
            if (! is_string($optionName) || preg_match(self::COMMAND_OPTION_KEY_PATTERN, $optionName) !== 1) {
                throw new RuntimeException('redact_command_options must be a list of command option names.');
            }

            if (! in_array($optionName, $redacted, true)) {
                $redacted[] = $optionName;
            }
        }

        return $redacted;
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
     *     redact_stdout?: bool,
     *     redact_stderr?: bool,
     *     redact_command_options?: list<string>,
     *     bind_application_key?: bool,
     *     bind_input?: bool,
     *     force_remote_host?: bool,
     * }  $transportOptions
     */
    private function operationId(array $transportOptions): string
    {
        $metadata = $transportOptions['metadata'] ?? [];
        $operationId = $metadata[self::OPERATION_ID_METADATA_KEY] ?? null;

        if (is_string($operationId) && trim($operationId) !== '') {
            return $operationId;
        }

        return (string) Str::uuid();
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
     *     force_remote_host?: bool,
     * }
     */
    private function transportDispatchOptions(Node $node, array $transportOptions): array
    {
        $environment = $this->localExecutorEnvironment($node, $transportOptions);

        unset(
            $transportOptions['redact_stdout'],
            $transportOptions['redact_stderr'],
            $transportOptions['redact_command_options'],
        );

        if (array_key_exists(self::BIND_APPLICATION_KEY_OPTION, $transportOptions)) {
            unset($transportOptions[self::BIND_APPLICATION_KEY_OPTION]);
        }

        if (array_key_exists(self::BIND_INPUT_OPTION, $transportOptions)) {
            unset($transportOptions[self::BIND_INPUT_OPTION]);
        }

        $transportOptions['environment'] = $environment;

        return $transportOptions;
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     * @return array<string, string>
     */
    private function localExecutorEnvironment(Node $node, array $transportOptions): array
    {
        $environment = $this->transportEnvironment($transportOptions);
        $home = $environment['HOME'] ?? $this->defaultLocalExecutorHome($node);
        $forceRemoteHost = ($transportOptions['force_remote_host'] ?? false) === true;

        $environment['HOME'] = $home;

        // force_remote_host dispatches over SSH. Process::env only affects the local
        // SSH client, so inventing ORBIT_CONFIG_PATH here would bind a context key
        // the remote host CLI never observes. Caller-supplied keys remain allowed.
        if (! $forceRemoteHost) {
            $environment['ORBIT_CONFIG_PATH'] ??= "{$home}/.config/orbit/config.json";
        }

        if (! $node->hasActiveRole('gateway')) {
            $environment['ORBIT_BIN_PATH'] = FleetUpdateNodeCliLauncher::binPath($node);
        }

        if ($this->shouldBindApplicationKey($transportOptions)) {
            $environment['APP_KEY'] = $this->applicationKey;
        } else {
            unset($environment['APP_KEY']);
        }

        return $environment;
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
        $transportOptions[self::BIND_APPLICATION_KEY_OPTION] = false;

        return $transportOptions;
    }

    private function defaultLocalExecutorHome(Node $node): string
    {
        return NodeHostPaths::homeDirectoryFor($node->platform, $node->user);
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     */
    private function shouldBindApplicationKey(array $transportOptions): bool
    {
        if (! array_key_exists(self::BIND_APPLICATION_KEY_OPTION, $transportOptions)) {
            return true;
        }

        if (is_bool($transportOptions[self::BIND_APPLICATION_KEY_OPTION])) {
            return $transportOptions[self::BIND_APPLICATION_KEY_OPTION];
        }

        throw new RuntimeException(self::BIND_APPLICATION_KEY_OPTION.' must be a boolean.');
    }

    /**
     * @param  array{
     *     operationId: string,
     *     operationToken: string,
     *     auditLine: string,
     *     argv: list<string>,
     *     commandContext: OperationTokenCommandContext,
     * }  $dispatch
     */
    private function runAgentPush(
        Node $node,
        array $dispatch,
        NodeCommandEnvelope $envelope,
    ): RemoteShellResult {
        $result = app(NodeAgentPushClient::class)->execute($node, $envelope, $dispatch['operationToken']);

        return $this->agentPushResult($result);
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
     * @mago-expect lint:excessive-parameter-list
     */
    private function runGatewayLocal(
        Node $node,
        string $commandName,
        array $arguments,
        array $commandOptions,
        array $dispatch,
        array $transportOptions,
        bool $forceRemoteHost = false,
    ): RemoteShellResult {
        $dispatchOptions = $this->transportDispatchOptions($node, $transportOptions);
        $script = $this->commands->build(
            targetNode: $node,
            commandName: $commandName,
            arguments: $arguments,
            options: $commandOptions,
            operationToken: $dispatch['operationToken'],
        );

        if ($forceRemoteHost) {
            $dispatchOptions['force_remote_host'] = true;

            // Host-owned gateway doctor checks leave the orbit-gateway container via
            // the host substrate executor (same lane as SshRemoteShell host work).
            // The host CLI is the sole operation-token verifier/consumer; do not
            // pre-authorize here or the one-use token is already spent on the host.
            // @orbit-ssh-lane provisioning-ssh
            return app(RemoteHostExecutor::class)->run(
                node: $node,
                script: $script,
                options: $dispatchOptions,
            );
        }

        $trustedExecution = app(GatewayLocalOperationTokenAuthorizer::class)->authorize(
            compactToken: $dispatch['operationToken'],
            expectedNode: $node->name,
            expectedCommand: $commandName,
            commandContext: $dispatch['commandContext'],
        );
        $dispatchOptions['environment'] = [
            ...($dispatchOptions['environment'] ?? []),
            ...$trustedExecution->environment(),
        ];

        return app(RemoteOrbitGatewayExecutor::class)->run(
            node: $node,
            script: $script,
            options: $dispatchOptions,
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
     * @param  array<string, mixed>  $transportOptions
     * @param  callable(string): void  $onOutput
     */
    private function streamAgentPush(
        Node $node,
        array $dispatch,
        string $operationId,
        array $transportOptions,
        callable $onOutput,
    ): NodeAgentPushStreamResult {
        $envelope = NodeCommandEnvelope::agentPushBinary(
            operationId: $operationId,
            binary: 'orbit',
            argv: $dispatch['argv'],
            input: $this->input($transportOptions),
            cwd: $this->cwd($transportOptions),
            environment: $this->localExecutorEnvironment($node, $transportOptions),
            timeoutSeconds: $this->streamTimeoutSeconds($transportOptions),
            stream: true,
        );
        $transport = app(NodeCommandTransportSelector::class)->select($node, $envelope);

        if ($transport !== NodeTransport::AgentPush) {
            throw new RuntimeException('agent-push streaming transport is unavailable');
        }

        return app(NodeAgentPushClient::class)->stream($node, $envelope, $dispatch['operationToken'], $onOutput);
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

    private function agentPushResult(NodeAgentPushResult $result): RemoteShellResult
    {
        $stdout = '';
        $stderr = '';

        foreach ($result->frames as $frame) {
            $message = $frame['message'] ?? '';

            if (! is_string($message)) {
                continue;
            }

            if (! array_key_exists('type', $frame) || ! is_string($frame['type'])) {
                continue;
            }

            $type = $frame['type'];

            if ($type === 'stderr') {
                $stderr .= $message;

                continue;
            }

            if ($type === 'stdout') {
                $stdout .= $message;
            }
        }

        return new RemoteShellResult(
            exitCode: $result->exitCode ?? 1,
            stdout: $stdout,
            stderr: $stderr,
            // Record the gateway-observed Agent dispatch round trip.
            durationMs: $result->timings['gateway_post_ms'],
        );
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     */
    private function timeoutSeconds(array $transportOptions): int
    {
        if (! array_key_exists('timeout', $transportOptions)) {
            return 30;
        }

        if (is_int($transportOptions['timeout']) && $transportOptions['timeout'] > 0) {
            return $transportOptions['timeout'];
        }

        throw new RuntimeException('timeout must be a positive integer.');
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     */
    private function streamTimeoutSeconds(array $transportOptions): int
    {
        if (! array_key_exists('timeout', $transportOptions)) {
            return 0;
        }

        if (is_int($transportOptions['timeout']) && $transportOptions['timeout'] >= 0) {
            return $transportOptions['timeout'];
        }

        throw new RuntimeException('stream timeout must be a non-negative integer.');
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     */
    private function input(array $transportOptions): ?string
    {
        if (! array_key_exists('input', $transportOptions)) {
            return null;
        }

        if (is_string($transportOptions['input'])) {
            return $transportOptions['input'];
        }

        throw new RuntimeException('input must be a string.');
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     */
    private function boundInput(array $transportOptions): ?string
    {
        if (! $this->shouldBindInput($transportOptions)) {
            return null;
        }

        return $this->input($transportOptions);
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     */
    private function shouldBindInput(array $transportOptions): bool
    {
        if (! array_key_exists(self::BIND_INPUT_OPTION, $transportOptions)) {
            return true;
        }

        if (is_bool($transportOptions[self::BIND_INPUT_OPTION])) {
            return $transportOptions[self::BIND_INPUT_OPTION];
        }

        throw new RuntimeException(self::BIND_INPUT_OPTION.' must be a boolean.');
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     */
    private function cwd(array $transportOptions): ?string
    {
        if (! array_key_exists('cwd', $transportOptions)) {
            return null;
        }

        if (is_string($transportOptions['cwd'])) {
            return $transportOptions['cwd'];
        }

        throw new RuntimeException('cwd must be a string.');
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     * @return array<string, string>
     */
    private function transportEnvironment(array $transportOptions): array
    {
        if (! array_key_exists('environment', $transportOptions)) {
            return [];
        }

        if ($transportOptions['environment'] === []) {
            return [];
        }

        if (! is_array($transportOptions['environment'])) {
            throw new RuntimeException('environment must be an array of string values.');
        }

        $resolved = [];

        array_walk($transportOptions['environment'], static function (mixed $value, int|string $key) use (
            &$resolved,
        ): void {
            if (! is_string($key) || ! is_string($value)) {
                throw new RuntimeException('environment must be an array of string values.');
            }

            $resolved[$key] = $value;
        });

        return $resolved;
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
