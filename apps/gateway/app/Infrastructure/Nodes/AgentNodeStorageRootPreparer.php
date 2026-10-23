<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Node;

/** Checks and creates a Node storage root through the agent's `nodes.prepare-storage-root`. */
final readonly class AgentNodeStorageRootPreparer implements NodeStorageRootPreparer
{
    public function __construct(
        private AgentCommandRunner $agents,
    ) {}

    public function inspect(Node $node, ManagedUserAccount $account, StoragePath $path): void
    {
        $this->run($node, $account, $path, createMissing: false);
    }

    public function prepare(Node $node, ManagedUserAccount $account, StoragePath $root): void
    {
        $this->run($node, $account, $root, createMissing: true);
    }

    private function run(Node $node, ManagedUserAccount $account, StoragePath $path, bool $createMissing): void
    {
        AgentStep::run($this->agents, $node, 'nodes.prepare-storage-root', [
            'root' => $path->value,
            'user' => $account->user,
            'group' => $account->group,
            'create_missing' => $createMissing,
        ], 'node-storage-root', 'node.settings_root_failed', 'App development');
    }
}
