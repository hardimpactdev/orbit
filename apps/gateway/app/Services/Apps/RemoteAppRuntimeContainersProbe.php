<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class RemoteAppRuntimeContainersProbe
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function stdout(Node $node): string
    {
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:app-runtime-containers:probe',
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'app-runtime-containers.probe',
                ],
                'strict' => true,
                'timeout' => 30,
            ],
        );

        if (! $result->successful()) {
            return 'orbit-container-scan:error '.trim($result->output())."\n";
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
