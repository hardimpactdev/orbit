<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;

final readonly class WorkspacesProbe
{
    public function key(): string
    {
        return 'workspaces';
    }

    public function label(): string
    {
        return 'Workspaces';
    }

    public function introspect(Workspace $workspace): ProbeSnapshot
    {
        return new ProbeSnapshot([]);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(Workspace $workspace, ProbeSnapshot $snapshot): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkRecordCompleteness($workspace));
        $drift = array_merge($drift, $this->checkParentApp($workspace));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(Workspace $workspace): array
    {
        if (
            ! is_string($workspace->name)
            || $workspace->name === ''
            || ! is_int($workspace->app_id)
            || ! is_string($workspace->path)
            || $workspace->path === ''
            || ! is_string($workspace->effectivePhpVersion())
            || $workspace->effectivePhpVersion() === ''
            || ! is_string($workspace->getRawOriginal('lifecycle_status'))
            || $workspace->getRawOriginal('lifecycle_status') === ''
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Workspace record for {$workspace->name} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkParentApp(Workspace $workspace): array
    {
        $workspace->loadMissing('app.node');

        if (! $workspace->app instanceof App) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.parent_app_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} points at a missing parent app.",
                ),
            ];
        }

        if (
            ! $workspace->app->node instanceof Node
            || $workspace->app->node->role !== 'app'
            || $workspace->app->node->status !== 'active'
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.parent_app_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} parent app {$workspace->app->name} is not on an active app node.",
                ),
            ];
        }

        return [];
    }
}
