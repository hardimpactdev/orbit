<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\S3\S3DoctorProbe;
use App\Services\Tools\ToolsProbe;
use App\Services\WebSockets\WebSocketDoctorProbe;
use Illuminate\Support\Collection;

final readonly class DoctorToolFamilyProbe
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private DoctorFamilyProbeRunner $familyProbeRunner,
        private ToolsProbe $toolsProbe,
        private NodeRoleAssignments $nodeRoleAssignments,
        private WebSocketDoctorProbe $webSocketDoctorProbe,
        private S3DoctorProbe $s3DoctorProbe,
        private DnsRuntimeProbe $dnsRuntimeProbe,
        private DoctorIssueFactory $doctorIssueFactory,
    ) {}

    /**
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return list<DoctorIssue>
     */
    public function probe(Node $node, ?string $key, ?callable $onFamilyProgress): array
    {
        $tools = $this->toolsForNode($node);
        $webSocketAssignment = $this->activeAssignment($node, NodeRoleName::WebSocket);
        $s3Assignment = $this->activeAssignment($node, NodeRoleName::S3);
        $probeDnsRuntime = $this->shouldProbeDnsRuntime($node);
        $total =
            $tools->count()
            + ($webSocketAssignment instanceof NodeRoleAssignment ? 1 : 0)
            + ($s3Assignment instanceof NodeRoleAssignment ? 1 : 0)
            + ($probeDnsRuntime ? 1 : 0);

        return $this->familyProbeRunner->run(
            node: $node,
            family: 'tool',
            total: $total,
            key: $key,
            onFamilyProgress: $onFamilyProgress,
            probe: function (callable $addIssue, callable $advance) use (
                $node,
                $probeDnsRuntime,
                $s3Assignment,
                $tools,
                $webSocketAssignment,
            ): void {
                $this->probeTools($tools, $addIssue, $advance);

                if ($webSocketAssignment instanceof NodeRoleAssignment) {
                    foreach ($this->webSocketDoctorProbe->toolDrift($node, $webSocketAssignment) as $entry) {
                        $addIssue($this->nodeIssue($entry, $node));
                    }

                    $advance();
                }

                if ($s3Assignment instanceof NodeRoleAssignment) {
                    foreach ($this->s3DoctorProbe->toolDrift($node, $s3Assignment) as $entry) {
                        $addIssue($this->nodeIssue($entry, $node));
                    }

                    $advance();
                }

                if ($probeDnsRuntime) {
                    foreach ($this->dnsRuntimeProbe->probe() as $entry) {
                        $addIssue($this->nodeIssue($entry, $node));
                    }

                    $advance();
                }
            },
        );
    }

    /**
     * @param  iterable<int, NodeTool>  $tools
     * @param  callable(DoctorIssue): void  $addIssue
     * @param  callable(): void  $advance
     */
    private function probeTools(iterable $tools, callable $addIssue, callable $advance): void
    {
        foreach ($tools as $tool) {
            $snapshot = $this->toolsProbe->introspect($tool);

            foreach ($this->toolsProbe->diff($tool, $snapshot) as $entry) {
                $addIssue($this->toolIssue($entry, $tool));
            }

            $advance();
        }
    }

    /**
     * @return Collection<int, NodeTool>
     */
    private function toolsForNode(Node $node): Collection
    {
        /** @mago-expect lint:inline-variable-return */
        $tools = NodeTool::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->orderBy('id')
            ->get()
            ->values();

        /** @var Collection<int, NodeTool> $tools */
        return $tools;
    }

    private function activeAssignment(Node $node, NodeRoleName $role): ?NodeRoleAssignment
    {
        return $this->nodeRoleAssignments->activeAssignment($node, $role->value);
    }

    private function shouldProbeDnsRuntime(Node $node): bool
    {
        return (
            $this->nodeRoleAssignments->nodeIsGateway($node) && $this->nodeRoleAssignments->nodeHasActiveVpnRole($node)
        );
    }

    private function toolIssue(DriftEntry $entry, NodeTool $tool): DoctorIssue
    {
        $tool->loadMissing('node');

        return $this->doctorIssueFactory->fromDriftEntry(
            $entry,
            $tool->node?->name,
            detail: [
                ...($entry->detail ?? []),
                'tool' => $tool->name,
            ],
        );
    }

    private function nodeIssue(DriftEntry $entry, Node $node): DoctorIssue
    {
        return $this->doctorIssueFactory->fromDriftEntry(
            $entry,
            $node->name,
            detail: $entry->detail ?? [],
        );
    }
}
