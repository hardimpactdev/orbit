<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Support\Collection;

final readonly class DoctorNodeDnsProjectionCheck
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private DnsmasqReconciler $dnsmasqReconciler,
        private NodeDnsProjectionProbe $nodeDnsProjectionProbe,
        private DoctorIssueFactory $doctorIssueFactory,
    ) {}

    /**
     * The DNS runtime consumes one shared node projection on the gateway VPN
     * host. Check it only there, but attribute each mismatch to its source
     * node. Skip the check when the runtime does not mount the projection so
     * an unrelated host file cannot report false drift.
     *
     * @return list<DoctorIssue>
     */
    public function issues(Node $node): array
    {
        if (! $this->shouldProbe($node)) {
            return [];
        }

        if (! $this->dnsmasqReconciler->projectionDirectoryIsMounted()) {
            return [];
        }

        $issues = [];

        foreach ($this->sources() as $source) {
            foreach ($this->nodeDnsProjectionProbe->drift($source) as $entry) {
                $issues[] = $this->doctorIssueFactory->fromDriftEntry(
                    $entry,
                    $source->name,
                    detail: $entry->detail ?? [],
                );
            }
        }

        return $issues;
    }

    private function shouldProbe(Node $node): bool
    {
        return (
            $this->nodeRoleAssignments->nodeIsGateway($node) && $this->nodeRoleAssignments->nodeHasActiveVpnRole($node)
        );
    }

    /**
     * @return list<Node>
     */
    private function sources(): array
    {
        /** @var Collection<int, Node> $nodes */
        $nodes = Node::query()
            ->with('roleAssignments')
            ->where('status', NodeStatus::Active->value)
            ->orderBy('id')
            ->get();

        $sources = [];

        foreach ($nodes as $node) {
            $sources[] = $node;
        }

        return $sources;
    }
}
