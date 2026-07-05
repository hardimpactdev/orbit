<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class RemoteAppRuntimeExtensionsProbe
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    /**
     * @return array{exit_code: int|null, stdout: string, stderr: string}
     */
    public function probe(Node $node, string $container): array
    {
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:app-runtime-extensions:probe',
            arguments: [$container],
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'app-runtime-extensions.probe',
                ],
                'strict' => true,
                'timeout' => 45,
            ],
        );

        if (! $result->successful()) {
            return [
                'exit_code' => $result->exitCode,
                'stdout' => $result->stdout,
                'stderr' => $result->stderr,
            ];
        }

        return $this->data($result);
    }

    /**
     * @return array{exit_code: int|null, stdout: string, stderr: string}
     */
    private function data(RemoteShellResult $result): array
    {
        /** @var mixed $payload */
        $payload = json_decode($result->stdout, associative: true);

        if (! is_array($payload)) {
            return ['exit_code' => null, 'stdout' => $result->stdout, 'stderr' => $result->stderr];
        }

        /** @var mixed $success */
        $success = $payload['success'] ?? null;

        if (! is_array($success)) {
            return ['exit_code' => null, 'stdout' => $result->stdout, 'stderr' => $result->stderr];
        }

        /** @var mixed $data */
        $data = $success['data'] ?? null;

        if (! is_array($data)) {
            return ['exit_code' => null, 'stdout' => $result->stdout, 'stderr' => $result->stderr];
        }

        return [
            'exit_code' => is_int($data['exit_code'] ?? null) ? $data['exit_code'] : null,
            'stdout' => is_string($data['stdout'] ?? null) ? $data['stdout'] : '',
            'stderr' => is_string($data['stderr'] ?? null) ? $data['stderr'] : '',
        ];
    }
}
