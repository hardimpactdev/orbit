<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;

final readonly class RemoteAppIntrospectProbe
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
    ) {}

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    public function snapshot(Node $node, array $payload): array
    {
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:app-introspect:probe',
            transportOptions: [
                'input' => json_encode($payload, JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'app.introspect',
                ],
                'strict' => true,
                'timeout' => 45,
            ],
        );

        return $this->snapshotData($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotData(RemoteShellResult $result): array
    {
        $data = RemoteShellSuccessData::fromJsonEnvelope($result);

        /** @var mixed $snapshot */
        $snapshot = $data['snapshot'] ?? null;

        if (! is_array($snapshot)) {
            return [];
        }

        /** @var array<string, mixed> $snapshot */
        return $snapshot;
    }
}
