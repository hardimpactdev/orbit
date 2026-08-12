<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\NodesProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\S3\S3DoctorProbe;
use App\Services\WebSockets\WebSocketDoctorProbe;

final readonly class DoctorNodeFamilyProbe
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private DoctorFamilyProbeRunner $familyProbeRunner,
        private NodesProbe $nodesProbe,
        private NodeRoleAssignments $nodeRoleAssignments,
        private DoctorNodeDnsProjectionCheck $dnsProjectionCheck,
        private WebSocketDoctorProbe $webSocketDoctorProbe,
        private S3DoctorProbe $s3DoctorProbe,
        private DoctorIssueFactory $doctorIssueFactory,
    ) {}

    /**
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return list<DoctorIssue>
     */
    public function probe(
        Node $node,
        ?string $key,
        ?callable $onFamilyProgress,
    ): array {
        $webSocketAssignment = $this->activeAssignment($node, NodeRoleName::WebSocket);
        $s3Assignment = $this->activeAssignment($node, NodeRoleName::S3);
        $total =
            2
            + ($webSocketAssignment instanceof NodeRoleAssignment ? 1 : 0)
            + ($s3Assignment instanceof NodeRoleAssignment ? 1 : 0);

        return $this->familyProbeRunner->run(
            node: $node,
            family: 'node',
            total: $total,
            key: $key,
            onFamilyProgress: $onFamilyProgress,
            probe: function (callable $addIssue, callable $advance) use (
                $node,
                $key,
                $webSocketAssignment,
                $s3Assignment,
            ): void {
                $this->probeNode($node, $key, $addIssue);
                $advance();

                foreach ($this->dnsProjectionCheck->issues($node) as $issue) {
                    $addIssue($issue);
                }

                $advance();

                if ($webSocketAssignment instanceof NodeRoleAssignment) {
                    foreach ($this->webSocketDoctorProbe->nodeDrift($node, $webSocketAssignment) as $entry) {
                        $addIssue($this->issue($entry, $node));
                    }

                    $advance();
                }

                if ($s3Assignment instanceof NodeRoleAssignment) {
                    foreach ($this->s3DoctorProbe->nodeDrift($node, $s3Assignment) as $entry) {
                        $addIssue($this->issue($entry, $node));
                    }

                    $advance();
                }
            },
        );
    }

    private function probeNode(Node $node, ?string $key, callable $addIssue): void
    {
        $snapshot = $this->nodesProbe->introspect($node);

        foreach ($this->nodesProbe->diff($node, $snapshot, $key) as $entry) {
            $addIssue($this->issue($entry, $node));
        }
    }

    private function activeAssignment(Node $node, NodeRoleName $role): ?NodeRoleAssignment
    {
        return $this->nodeRoleAssignments->activeAssignment($node, $role->value);
    }

    private function issue(DriftEntry $entry, Node $node): DoctorIssue
    {
        return $this->doctorIssueFactory->fromDriftEntry(
            $entry,
            $node->name,
            detail: $entry->detail ?? [],
        );
    }
}
