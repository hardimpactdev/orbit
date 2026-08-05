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

    public function read(
        Node $node,
        string $absolutePath,
        string $authorizedRoot,
        int $lines,
    ): RemoteShellResult {
        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:application-log',
            transportOptions: $this->transportOptions(
                absolutePath: $absolutePath,
                authorizedRoot: $authorizedRoot,
                lines: $lines,
                follow: false,
                operationStream: null,
                operationId: 'application.log.read',
                timeout: 120,
            ),
        );
    }

    /**
     * @param  callable(string): void  $onOutput
     * @param  array<string, mixed>|null  $operationStream
     */
    public function follow(
        Node $node,
        string $absolutePath,
        string $authorizedRoot,
        int $lines,
        callable $onOutput,
        ?array $operationStream = null,
    ): void {
        $this->localExecutor->streamInternal(
            node: $node,
            commandName: 'internal:application-log',
            transportOptions: $this->transportOptions(
                absolutePath: $absolutePath,
                authorizedRoot: $authorizedRoot,
                lines: $lines,
                follow: true,
                operationStream: $operationStream,
                operationId: 'application.log.follow',
                timeout: 0,
            ),
            onOutput: $onOutput,
        );
    }

    /**
     * @param  array<string, mixed>|null  $operationStream
     * @return array<string, mixed>
     */
    private function transportOptions(
        string $absolutePath,
        string $authorizedRoot,
        int $lines,
        bool $follow,
        ?array $operationStream,
        string $operationId,
        int $timeout,
    ): array {
        $input = [
            'absolute_path' => $absolutePath,
            'authorized_root' => $authorizedRoot,
            'lines' => $lines,
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
