<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class RemoteAppSourcePathProbe
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function exists(Node $node, string $path): bool
    {
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:app-source-path:probe',
            arguments: [$path],
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'app-source-path.probe',
                ],
                'strict' => true,
                'timeout' => 15,
            ],
        );

        if (! $result->successful()) {
            return false;
        }

        return ($this->data($result)['exists'] ?? false) === true;
    }

    /**
     * @return array{exists?: bool}
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

        if (! is_array($data) || ! is_bool($data['exists'] ?? null)) {
            return [];
        }

        return ['exists' => $data['exists']];
    }
}
