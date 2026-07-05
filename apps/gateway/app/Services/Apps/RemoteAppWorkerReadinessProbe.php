<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class RemoteAppWorkerReadinessProbe
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function stdout(Node $node, string $path, string $workerFile): string
    {
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:app-worker-readiness:probe',
            arguments: [$path, $workerFile],
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'app-worker-readiness.probe',
                ],
                'strict' => true,
                'timeout' => 15,
            ],
        );

        if (! $result->successful()) {
            return $result->stdout;
        }

        return $this->data($result)['stdout'] ?? $result->stdout;
    }

    /**
     * @return array{stdout?: string}
     */
    private function data(RemoteShellResult $result): array
    {
        /** @var mixed $payload */
        $payload = json_decode($result->stdout, associative: true);

        if (! is_array($payload)) {
            return [];
        }

        /** @var mixed $success */
        $success = $payload['success'] ?? null;

        if (! is_array($success)) {
            return [];
        }

        /** @var mixed $data */
        $data = $success['data'] ?? null;

        if (! is_array($data) || ! is_string($data['stdout'] ?? null)) {
            return [];
        }

        return ['stdout' => $data['stdout']];
    }
}
