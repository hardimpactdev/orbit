<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class RemoteProcessLogs
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    /**
     * @mago-expect lint:excessive-parameter-list
     */
    public function read(
        Node $node,
        string $backend,
        string $runtimeUnit,
        int $lines,
        ?string $stdoutPath = null,
        ?string $stderrPath = null,
    ): RemoteShellResult {
        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:process-logs',
            transportOptions: [
                'input' => json_encode([
                    'backend' => $backend,
                    'runtime_unit' => $runtimeUnit,
                    'lines' => $lines,
                    'follow' => false,
                    ...($stdoutPath !== null ? ['stdout_path' => $stdoutPath] : []),
                    ...($stderrPath !== null ? ['stderr_path' => $stderrPath] : []),
                ], JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'process.logs.read',
                ],
                'strict' => false,
                'timeout' => 120,
            ],
        );
    }

    /**
     * @param  callable(string): void  $onOutput
     */
    /**
     * @mago-expect lint:excessive-parameter-list
     */
    public function follow(
        Node $node,
        string $backend,
        string $runtimeUnit,
        int $lines,
        callable $onOutput,
        ?string $stdoutPath = null,
        ?string $stderrPath = null,
    ): void {
        $this->localExecutor->streamInternal(
            node: $node,
            commandName: 'internal:process-logs',
            transportOptions: [
                'input' => json_encode([
                    'backend' => $backend,
                    'runtime_unit' => $runtimeUnit,
                    'lines' => $lines,
                    'follow' => true,
                    ...($stdoutPath !== null ? ['stdout_path' => $stdoutPath] : []),
                    ...($stderrPath !== null ? ['stderr_path' => $stderrPath] : []),
                ], JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'process.logs.follow',
                ],
                'strict' => false,
                'timeout' => 0,
            ],
            onOutput: $onOutput,
        );
    }
}
