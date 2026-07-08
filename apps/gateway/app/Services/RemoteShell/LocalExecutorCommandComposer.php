<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Models\Node;
use SensitiveParameter;

interface LocalExecutorCommandComposer
{
    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     */
    public function build(
        Node $targetNode,
        string $commandName,
        array $arguments,
        array $options,
        #[SensitiveParameter]
        string $operationToken,
    ): string;

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     * @return list<string>
     */
    public function buildArgv(
        Node $targetNode,
        string $commandName,
        array $arguments,
        array $options,
        #[SensitiveParameter]
        string $operationToken,
    ): array;

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     */
    public function buildAuditLine(
        Node $targetNode,
        string $commandName,
        array $arguments,
        array $options,
        #[SensitiveParameter]
        string $operationToken,
    ): string;
}
