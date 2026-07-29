<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RunsInternalCommands;

final readonly class RemoteAppSourcePathProbe
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
    ) {}

    public function exists(Node $node, string $path): bool
    {
        return $this->inspect($node, $path)['exists'];
    }

    /**
     * @return array{path: string, exists: bool, resolved_path: string|null, within_boundary: bool}
     */
    public function inspect(Node $node, string $path, ?string $boundary = null): array
    {
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:app-source-path:probe',
            arguments: [$path],
            commandOptions: $boundary === null ? [] : ['boundary' => $boundary],
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'app-source-path.probe',
                ],
                'strict' => true,
                'timeout' => 15,
            ],
        );

        if (! $result->successful()) {
            return $this->missing($path);
        }

        return $this->data($result, $path);
    }

    /**
     * @return array{path: string, exists: bool, resolved_path: string|null, within_boundary: bool}
     */
    private function data(RemoteShellResult $result, string $path): array
    {
        /** @var mixed $payload */
        $payload = json_decode($result->stdout, associative: true);

        if (! is_array($payload)) {
            return $this->missing($path);
        }

        /** @var mixed $success */
        $success = $payload['success'] ?? null;

        if (! is_array($success)) {
            return $this->missing($path);
        }

        /** @var mixed $data */
        $data = $success['data'] ?? null;

        if (! is_array($data) || ! is_bool($data['exists'] ?? null)) {
            return $this->missing($path);
        }

        return [
            'path' => is_string($data['path'] ?? null) ? $data['path'] : $path,
            'exists' => $data['exists'],
            'resolved_path' => is_string($data['resolved_path'] ?? null) ? $data['resolved_path'] : null,
            'within_boundary' => ($data['within_boundary'] ?? false) === true,
        ];
    }

    /**
     * @return array{path: string, exists: false, resolved_path: null, within_boundary: false}
     */
    private function missing(string $path): array
    {
        return [
            'path' => $path,
            'exists' => false,
            'resolved_path' => null,
            'within_boundary' => false,
        ];
    }
}
