<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\Exceptions\RemoteShellProtocolException;
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
        $data = RemoteShellSuccessData::fromJsonEnvelopeOrFail($result);

        /** @var mixed $snapshot */
        $snapshot = $data['snapshot'] ?? null;

        if (! is_array($snapshot)) {
            throw new RemoteShellProtocolException(
                'App introspect probe success.data.snapshot must be an object.',
            );
        }

        /** @var array<string, mixed> $snapshot */
        return $snapshot;
    }
}
