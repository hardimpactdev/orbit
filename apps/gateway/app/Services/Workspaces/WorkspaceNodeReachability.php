<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Exceptions\WorkspaceCreateFailed;
use App\Models\Node;
use App\Services\RemoteShell\RunsInternalCommands;
use Throwable;

final readonly class WorkspaceNodeReachability
{
    public function __construct(
        private ?RunsInternalCommands $localExecutor = null,
    ) {}

    public function ensureReachable(Node $node): void
    {
        try {
            $preflight = $this->localExecutor()->runInternal(
                node: $node,
                commandName: 'internal:executor:verify',
                transportOptions: [
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => 'workspace.node.reachable',
                    ],
                    'timeout' => 30,
                    'throw' => false,
                ],
            );
        } catch (Throwable $throwable) {
            $this->throwUnreachable($node, $throwable->getMessage());
        }

        if ($preflight->successful()) {
            return;
        }

        $output = trim($preflight->output());

        $this->throwUnreachable($node, $output === '' ? 'agent preflight failed' : $output);
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RunsInternalCommands::class);
    }

    private function throwUnreachable(Node $node, string $reason): never
    {
        throw new WorkspaceCreateFailed(
            'workspace.node_unreachable',
            "Gateway could not reach app node '{$node->name}' through agent transport before creating workspace intent.",
            [
                'node' => $node->name,
                'reason' => $reason,
            ],
        );
    }
}
