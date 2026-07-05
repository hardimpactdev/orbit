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

    public function read(Node $node, string $backend, string $runtimeUnit, int $lines): RemoteShellResult
    {
        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:process-logs',
            transportOptions: [
                'input' => json_encode([
                    'backend' => $backend,
                    'runtime_unit' => $runtimeUnit,
                    'lines' => $lines,
                    'follow' => false,
                ], JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'process.logs.read',
                ],
                'strict' => false,
                'timeout' => 120,
            ],
        );
    }
}
