<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Contracts\Loggable;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ActivityLogType;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationTokenFactory;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class RemoteLocalExecutor implements RemoteExecutor
{
    private const string OPERATION_ID_METADATA_KEY = 'ORBIT_OPERATION_ID';

    private const int OUTPUT_SUMMARY_BYTES = 4_096;

    private const string TRUNCATED_SUFFIX = '[truncated]';

    private const string START_UNSUPPORTED_MESSAGE = 'RemoteLocalExecutor::startInternal() is not supported. Long-running local-executor processes are not currently audited; use runInternal() for completion-based dispatch. See docs/execution-lanes.md.';

    public function __construct(
        private RemoteExecutor $transport,
        private LocalExecutorCommandBuilder $commands,
        private OperationTokenFactory $operationTokens,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
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
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $transportOptions
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $operationId = $this->operationId($transportOptions);
        $dispatch = $this->dispatchCommand(
            node: $node,
            commandName: $commandName,
            arguments: $arguments,
            commandOptions: $commandOptions,
            operationId: $operationId,
        );

        $this->logDispatching(
            node: $node,
            commandName: $commandName,
            arguments: $arguments,
            commandOptions: $commandOptions,
            operationId: $operationId,
            auditLine: $dispatch['auditLine'],
        );

        try {
            $result = $this->transport->run(
                node: $node,
                script: $dispatch['script'],
                options: $transportOptions,
            );
        } catch (RemoteShellFailed $exception) {
            $sanitizedResult = $this->sanitizedResult($exception->result, $dispatch['operationToken']);

            $this->logCompleted(
                node: $node,
                commandName: $commandName,
                operationId: $operationId,
                result: $sanitizedResult,
                operationToken: $dispatch['operationToken'],
            );

            throw new RemoteShellFailed(
                node: $exception->node,
                script: $this->redactOperationToken($exception->script, $dispatch['operationToken']),
                result: $sanitizedResult,
            );
        } catch (Throwable $throwable) {
            $this->logTransportException(
                node: $node,
                commandName: $commandName,
                operationId: $operationId,
                throwable: $throwable,
                operationToken: $dispatch['operationToken'],
            );

            $redactedMessage = $this->redactOperationToken($throwable->getMessage(), $dispatch['operationToken']);

            throw new RuntimeException(
                message: "Remote local executor transport failed: {$this->truncate($redactedMessage)}",
                code: (int) $throwable->getCode(),
            );
        }

        $this->logCompleted(
            node: $node,
            commandName: $commandName,
            operationId: $operationId,
            result: $result,
            operationToken: $dispatch['operationToken'],
        );

        return $result;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
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
     *     metadata?: array<string, string>,
     *     strict?: bool,
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
     * @return array{operationToken: string, script: string, auditLine: string}
     */
    private function dispatchCommand(
        Node $node,
        string $commandName,
        array $arguments,
        array $commandOptions,
        string $operationId,
    ): array {
        $this->commands->build(
            commandName: $commandName,
            arguments: $arguments,
            options: $commandOptions,
            operationToken: 'validation-placeholder',
        );

        $operationToken = $this->operationTokens->mint(
            operationId: $operationId,
            targetNode: (string) $node->name,
            command: $commandName,
        )->toString();

        return [
            'operationToken' => $operationToken,
            'script' => $this->commands->build(
                commandName: $commandName,
                arguments: $arguments,
                options: $commandOptions,
                operationToken: $operationToken,
            ),
            'auditLine' => $this->commands->buildAuditLine(
                commandName: $commandName,
                arguments: $arguments,
                options: $commandOptions,
                operationToken: $operationToken,
            ),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     */
    private function logDispatching(
        Node $node,
        string $commandName,
        array $arguments,
        array $commandOptions,
        string $operationId,
        string $auditLine,
    ): void {
        $this->activityLogger->log(
            new LocalExecutorActivity(
                event: 'local_executor.dispatching',
                subject: $node,
                description: 'Local executor operation dispatching',
                properties: [
                    'lane' => 'local-executor',
                    'status' => 'dispatching',
                    'operation_id' => $operationId,
                    'target_node_id' => $node->getKey(),
                    'target_node_name' => (string) $node->name,
                    'command' => $commandName,
                    'arguments' => $this->scalarPayload($arguments),
                    'command_options' => $this->scalarPayload($commandOptions),
                    'command_line' => $auditLine,
                ],
            ),
            channel: 'local_executor',
            causer: null,
        );
    }

    private function logCompleted(
        Node $node,
        string $commandName,
        string $operationId,
        RemoteShellResult $result,
        string $operationToken,
    ): void {
        $status = $result->successful() ? 'succeeded' : 'failed';

        $this->activityLogger->log(
            new LocalExecutorActivity(
                event: 'local_executor.completed',
                subject: $node,
                description: "Local executor operation {$status}",
                properties: [
                    'lane' => 'local-executor',
                    'status' => $status,
                    'operation_id' => $operationId,
                    'target_node_id' => $node->getKey(),
                    'target_node_name' => (string) $node->name,
                    'command' => $commandName,
                    'exit_code' => $result->exitCode,
                    'stdout_summary' => $this->outputSummary($result->stdout, $operationToken),
                    'stderr_summary' => $this->outputSummary($result->stderr, $operationToken),
                    'duration_ms' => $result->durationMs,
                ],
            ),
            channel: 'local_executor',
            causer: null,
        );
    }

    private function logTransportException(
        Node $node,
        string $commandName,
        string $operationId,
        Throwable $throwable,
        string $operationToken,
    ): void {
        $this->activityLogger->log(
            new LocalExecutorActivity(
                event: 'local_executor.completed',
                subject: $node,
                description: 'Local executor operation failed',
                properties: [
                    'lane' => 'local-executor',
                    'status' => 'failed',
                    'operation_id' => $operationId,
                    'target_node_id' => $node->getKey(),
                    'target_node_name' => (string) $node->name,
                    'command' => $commandName,
                    'exit_code' => null,
                    'stdout_summary' => '',
                    'stderr_summary' => '',
                    'exception_class' => $throwable::class,
                    'exception_message' => $this->outputSummary($throwable->getMessage(), $operationToken),
                ],
            ),
            channel: 'local_executor',
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

    private function outputSummary(string $output, string $operationToken): string
    {
        return $this->truncate($this->redactOperationToken($output, $operationToken));
    }

    private function redactOperationToken(string $value, string $operationToken): string
    {
        $redacted = preg_replace(
            '/--operation-token\s*(?:=\s*|\s+)(?:"[^"]*"|\'[^\']*\'|\S+)/',
            '--operation-token=<redacted>',
            $value,
        ) ?? $value;

        if ($operationToken === '') {
            return $redacted;
        }

        return str_replace($operationToken, '<redacted>', $redacted);
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= self::OUTPUT_SUMMARY_BYTES) {
            return $value;
        }

        return substr($value, 0, self::OUTPUT_SUMMARY_BYTES).self::TRUNCATED_SUFFIX;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, bool|float|int|string>
     */
    private function scalarPayload(array $values): array
    {
        $payload = [];

        foreach ($values as $key => $value) {
            if (is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
                $payload[$key] = $value;
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
     *     metadata?: array<string, string>,
     *     strict?: bool,
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
