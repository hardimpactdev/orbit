<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Operations\ProvisioningAgentInstaller;

final class NodeStoreProvisioningAgentInstaller extends ProvisioningAgentInstaller
{
    /**
     * @var list<array{node: string, status: string, role_count: int}>
     */
    public array $provisioningSnapshots = [];

    public function __construct() {}

    public function install(Node $node): RemoteShellResult
    {
        $this->provisioningSnapshots[] = [
            'node' => $node->name,
            'status' => $node->status->value,
            'role_count' => $node->roleAssignments()->count(),
        ];

        return new RemoteShellResult(exitCode: 0, stdout: 'agent-ready', stderr: '', durationMs: 1);
    }
}
