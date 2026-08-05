<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class RemoteApplicationLogs
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    /**
     * @param  array{absolute_path: string, authorized_root: string, lines: int}  $paths
     */
    public function read(Node $node, array $paths): RemoteShellResult
    {
        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:application-log',
            transportOptions: $this->transportOptions(
                paths: $paths,
                follow: false,
                operationStream: null,
                operationId: 'application.log.read',
                timeout: 120,
            ),
        );
    }

    /**
     * @param  array{absolute_path: string, authorized_root: string, lines: int}  $paths
     * @param  callable(string): void  $onOutput
     * @param  array<string, mixed>|null  $operationStream
     */
    public function follow(Node $node, array $paths, callable $onOutput, ?array $operationStream = null): void
    {
        $this->localExecutor->streamInternal(
            node: $node,
            commandName: 'internal:application-log',
            transportOptions: $this->transportOptions(
                paths: $paths,
                follow: true,
                operationStream: $operationStream,
                operationId: 'application.log.follow',
                timeout: 0,
            ),
            onOutput: $onOutput,
        );
    }

    /**
     * @param  array{absolute_path: string, authorized_root: string, lines: int}  $paths
     * @param  array<string, mixed>|null  $operationStream
     * @return array{
     *     input: string,
     *     metadata: array<string, string>,
     *     strict: bool,
     *     timeout: int
     * }
     */
    private function transportOptions(
        array $paths,
        bool $follow,
        ?array $operationStream,
        string $operationId,
        int $timeout,
    ): array {
        $input = [
            'absolute_path' => $paths['absolute_path'],
            'authorized_root' => $paths['authorized_root'],
            'lines' => $paths['lines'],
            'follow' => $follow,
        ];

        if ($operationStream !== null) {
            $input['operation_stream'] = $operationStream;
        }

        return [
            'input' => json_encode($input, JSON_THROW_ON_ERROR),
            'metadata' => [
                'ORBIT_OPERATION_ID' => $operationId,
            ],
            'strict' => false,
            'timeout' => $timeout,
        ];
    }
}
