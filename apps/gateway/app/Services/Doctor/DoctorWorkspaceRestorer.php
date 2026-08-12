<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Node;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class DoctorWorkspaceRestorer
{
    public function __construct(
        private WorkspacePlacement $workspacePlacement = new WorkspacePlacement,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    public function apply(Node $node, string $key, array $detail): ?array
    {
        $workspaceName = is_string($detail['workspace'] ?? null) ? $detail['workspace'] : null;

        if ($workspaceName === null) {
            return null;
        }

        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;
        $workspaces = Workspace::query()
            ->with(['app.node', 'app.instances', 'instance'])
            ->where('name', $workspaceName)
            ->whereHas('app', static function ($query) use ($appName): void {
                if ($appName !== null) {
                    $query->where('name', $appName);
                }
            })
            ->get();
        $workspace = $workspaces->first(
            fn (Workspace $workspace): bool => (
                $this->workspacePlacement->nodeForWorkspace($workspace)?->id === $node->id
            ),
        );

        if (! $workspace instanceof Workspace) {
            return null;
        }

        $workspace->loadMissing('app.node');

        return [
            'family' => 'workspace',
            'node' => $this->workspacePlacement->nodeForWorkspace($workspace)?->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'skipped',
            'summary' => "Skipped fix for {$key}: workspace auto-fix is not supported in the Docker-first runtime.",
        ];
    }
}
