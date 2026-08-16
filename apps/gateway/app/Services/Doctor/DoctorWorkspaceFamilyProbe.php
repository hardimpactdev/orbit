<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspacesProbe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class DoctorWorkspaceFamilyProbe
{
    public function __construct(
        private DoctorFamilyProbeRunner $familyProbeRunner,
        private WorkspacesProbe $workspacesProbe,
        private DoctorIssueFactory $doctorIssueFactory,
        private WorkspacePlacement $workspacePlacement,
    ) {}

    /**
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return list<DoctorIssue>
     */
    public function probe(Node $node, ?string $key, ?callable $onFamilyProgress): array
    {
        $workspaces = $this->workspacesForNode($node);

        return $this->familyProbeRunner->run(
            node: $node,
            family: 'workspace',
            total: $workspaces->count(),
            key: $key,
            onFamilyProgress: $onFamilyProgress,
            probe: function (callable $addIssue, callable $advance) use ($workspaces): void {
                foreach ($workspaces as $workspace) {
                    $snapshot = $this->workspacesProbe->introspect($workspace);

                    foreach ($this->workspacesProbe->diff($workspace, $snapshot) as $entry) {
                        $addIssue($this->workspaceIssue($entry, $workspace));
                    }

                    $advance();
                }
            },
        );
    }

    /**
     * @return Collection<int, Workspace>
     * @mago-expect lint:inline-variable-return
     */
    private function workspacesForNode(Node $node): Collection
    {
        /** @var Builder<Workspace> $query */
        $query = Workspace::query();

        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = $query
            ->with(['app.instances', 'instance'])
            ->orderBy('id')
            ->get();

        /** @var Collection<int, Workspace> $workspacesForNode */
        $workspacesForNode = $workspaces
            ->filter(
                fn (Workspace $workspace): bool => (
                    $this->workspacePlacement->nodeForWorkspace($workspace)?->id === $node->id
                ),
            )
            ->values();

        return $workspacesForNode;
    }

    private function workspaceIssue(DriftEntry $entry, Workspace $workspace): DoctorIssue
    {
        $workspace->loadMissing(['app.instances', 'instance']);

        return $this->doctorIssueFactory->fromDriftEntry(
            $entry,
            $this->workspacePlacement->nodeForWorkspace($workspace)?->name,
            detail: [
                ...($entry->detail ?? []),
                'workspace' => $workspace->name,
                'app' => $workspace->app?->name,
            ],
        );
    }
}
