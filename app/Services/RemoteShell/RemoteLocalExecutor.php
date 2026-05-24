<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Operations\OperationTokenFactory;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Str;

final readonly class RemoteLocalExecutor implements RemoteExecutor
{
    private const string OPERATION_ID_METADATA_KEY = 'ORBIT_OPERATION_ID';

    public function __construct(
        private RemoteExecutor $transport,
        private LocalExecutorCommandBuilder $commands,
        private OperationTokenFactory $operationTokens,
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
        return $this->transport->run(
            node: $node,
            script: $this->script($node, $commandName, $arguments, $commandOptions, $transportOptions),
            options: $transportOptions,
        );
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
        return $this->startInternal(
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
    public function startInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): InvokedProcess {
        return $this->transport->start(
            node: $node,
            script: $this->script($node, $commandName, $arguments, $commandOptions, $transportOptions),
            options: $transportOptions,
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
    private function script(
        Node $node,
        string $commandName,
        array $arguments,
        array $commandOptions,
        array $transportOptions,
    ): string {
        $this->commands->build(
            commandName: $commandName,
            arguments: $arguments,
            options: $commandOptions,
            operationToken: 'validation-placeholder',
        );

        $operationToken = $this->operationTokens->mint(
            operationId: $this->operationId($transportOptions),
            targetNode: (string) $node->name,
            command: $commandName,
        );

        return $this->commands->build(
            commandName: $commandName,
            arguments: $arguments,
            options: $commandOptions,
            operationToken: $operationToken->toString(),
        );
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
