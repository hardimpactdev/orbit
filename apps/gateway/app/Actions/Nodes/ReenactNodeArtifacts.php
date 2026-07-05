<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;

class ReenactNodeArtifacts
{
    /**
     * @param  list<string>  $changed
     * @return list<array<string, string>>
     */
    public function handle(Node $node, array $changed): array
    {
        if (! in_array('gateway_endpoint', $changed, true)) {
            return [];
        }

        if ($node->hasActiveRole('gateway')) {
            return [];
        }

        $endpoint = trim((string) $node->gateway_endpoint);

        if ($endpoint === '') {
            return [$this->warning()];
        }

        $result = app(RemoteLocalExecutor::class)->runInternal(
            node: $node,
            commandName: 'internal:wireguard-endpoint:rotate',
            arguments: ["{$endpoint}:51820"],
            transportOptions: [
                'timeout' => 30,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'node.gateway_endpoint.rotate',
                    'node' => $node->name,
                ],
            ],
        );

        if (! $result->successful()) {
            return [$this->warning()];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function warning(): array
    {
        return [
            'code' => 'node.artifact_enactment_failed',
            'message' => 'Node artifact re-enactment failed after intent update.',
            'family' => 'node',
            'next_command' => 'doctor --family=node --restore',
        ];
    }
}
