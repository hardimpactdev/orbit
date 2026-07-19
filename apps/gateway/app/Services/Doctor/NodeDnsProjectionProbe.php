<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Dns\NodeDnsmasqRecordsBuilder;
use Illuminate\Support\Facades\File;

final readonly class NodeDnsProjectionProbe
{
    public function __construct(
        private NodeDnsmasqRecordsBuilder $recordsBuilder,
        private string $rootPath,
    ) {}

    /** @return list<DriftEntry> */
    public function drift(Node $node): array
    {
        $expected = $this->recordsBuilder->directivesFor($node);

        if ($expected === [] || ! $this->dnsCapabilityRequired()) {
            return [];
        }

        $actual = $this->actualDirectives();
        $hostPrefix = 'address=/orbit.'.$node->tld.'/';
        $hostExpected = [$expected[0]];
        $hostActual = array_values(array_filter(
            $actual,
            static fn (string $directive): bool => str_starts_with($directive, $hostPrefix),
        ));
        $drift = [];

        if ($hostActual !== $hostExpected) {
            $drift[] = $this->entry($node, 'node_host', $hostExpected, $hostActual);
        }

        $tld = $node->tld;
        $wildcardExpected = array_slice($expected, offset: 1);
        $wildcardActual = array_values(array_filter(
            $actual,
            static fn (string $directive): bool => (
                str_starts_with($directive, "address=/{$tld}/")
                || $directive === "local=/{$tld}/"
            ),
        ));

        if ($wildcardActual !== $wildcardExpected) {
            $drift[] = $this->entry($node, 'wildcard', $wildcardExpected, $wildcardActual);
        }

        $orphaned = $this->orphanedDirectives($node, $actual);

        if ($orphaned !== []) {
            $drift[] = $this->entry($node, 'orphan', [], $orphaned);
        }

        return $drift;
    }

    /** @return list<string> */
    private function actualDirectives(): array
    {
        $path = $this->rootPath.'/'.DnsmasqReconciler::NodeRecords;

        if (! File::exists($path)) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode("\n", File::get($path))),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));
    }

    /**
     * @param  list<string>  $actual
     * @return list<string>
     */
    private function orphanedDirectives(Node $reportingNode, array $actual): array
    {
        if (! $this->isProjectionAnchor($reportingNode)) {
            return [];
        }

        $expected = $this->directivesFrom($this->recordsBuilder->buildGatewayState());
        $unexpected = $this->multisetDifference($actual, $expected);
        $activeTlds = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereNotNull('tld')
            ->pluck('tld')
            ->filter(static fn (mixed $tld): bool => is_string($tld))
            ->values()
            ->all();

        return array_values(array_filter(
            $unexpected,
            fn (string $directive): bool => ! array_any(
                $activeTlds,
                fn (string $tld): bool => $this->directiveBelongsToTld($directive, $tld),
            ),
        ));
    }

    private function isProjectionAnchor(Node $node): bool
    {
        /** @var Node|null $gatewayNode */
        $gatewayNode = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', static fn ($query) => $query
                ->where('role', NodeRoleName::Gateway->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->orderBy('id')
            ->first(['id']);

        /** @var Node|null $anchorNode */
        $anchorNode = $gatewayNode instanceof Node
            ? $gatewayNode
            : Node::query()
                ->where('status', NodeStatus::Active->value)
                ->orderBy('id')
                ->first(['id']);

        return $anchorNode instanceof Node && $node->is($anchorNode);
    }

    /** @return list<string> */
    private function directivesFrom(string $contents): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode("\n", $contents)),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function multisetDifference(array $left, array $right): array
    {
        foreach ($right as $expected) {
            $index = array_search($expected, $left, strict: true);

            if ($index !== false) {
                unset($left[$index]);
            }
        }

        return array_values($left);
    }

    private function directiveBelongsToTld(string $directive, string $tld): bool
    {
        return in_array(
            true,
            [
                str_starts_with($directive, "address=/orbit.{$tld}/"),
                str_starts_with($directive, "address=/{$tld}/"),
                $directive === "local=/{$tld}/",
            ],
            strict: true,
        );
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     */
    private function entry(Node $node, string $recordKind, array $expected, array $actual): DriftEntry
    {
        return new DriftEntry(
            family: 'node',
            key: 'node.dns_mapping_mismatch',
            kind: $actual === [] ? DriftKind::Missing : DriftKind::Divergent,
            summary: "Node-owned DNS {$recordKind} projection for {$node->name} is missing or mismatched.",
            detail: [
                'node' => $node->name,
                'record_kind' => $recordKind,
                'path' => $this->rootPath.'/'.DnsmasqReconciler::NodeRecords,
                'expected' => $expected,
                'actual' => $actual,
            ],
        );
    }

    private function dnsCapabilityRequired(): bool
    {
        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', static fn ($query) => $query
                ->where('role', NodeRoleName::Vpn->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->exists();
    }
}
