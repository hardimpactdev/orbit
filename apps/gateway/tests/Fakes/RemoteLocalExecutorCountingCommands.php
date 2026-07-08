<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\Node;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\LocalExecutorCommandComposer;
use SensitiveParameter;

final class RemoteLocalExecutorCountingCommands implements LocalExecutorCommandComposer
{
    /** @var list<string> */
    public array $calls = [];

    private LocalExecutorCommandBuilder $commands;

    public function __construct()
    {
        $this->commands = new LocalExecutorCommandBuilder;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     */
    #[Override]
    public function build(
        Node $targetNode,
        string $commandName,
        array $arguments,
        array $options,
        #[SensitiveParameter]
        string $operationToken,
    ): string {
        $this->calls[] = 'build';

        return $this->commands->build($targetNode, $commandName, $arguments, $options, $operationToken);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     * @return list<string>
     */
    #[Override]
    public function buildArgv(
        Node $targetNode,
        string $commandName,
        array $arguments,
        array $options,
        #[SensitiveParameter]
        string $operationToken,
    ): array {
        $this->calls[] = 'buildArgv';

        return $this->commands->buildArgv($targetNode, $commandName, $arguments, $options, $operationToken);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     */
    #[Override]
    public function buildAuditLine(
        Node $targetNode,
        string $commandName,
        array $arguments,
        array $options,
        #[SensitiveParameter]
        string $operationToken,
    ): string {
        $this->calls[] = 'buildAuditLine';

        return $this->commands->buildAuditLine($targetNode, $commandName, $arguments, $options, $operationToken);
    }
}
