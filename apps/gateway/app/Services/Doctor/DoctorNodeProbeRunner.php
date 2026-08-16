<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorTargetScope;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

/** @mago-expect lint:excessive-parameter-list */
final readonly class DoctorNodeProbeRunner
{
    private const array FAMILY_ORDER = [
        'node',
        'app',
        'workspace',
        'process',
        'proxy',
        'firewall_rule',
        'tool',
        'schedule',
        'database_connection',
    ];

    public function __construct(
        private DoctorNodeFamilyResolver $nodeFamilies,
        private DoctorNodeFamilyProbe $nodeFamilyProbe,
        private DoctorAppFamilyProbe $appFamilyProbe,
        private DoctorWorkspaceFamilyProbe $workspaceFamilyProbe,
        private DoctorProcessFamilyProbe $processFamilyProbe,
        private DoctorProxyFamilyProbe $proxyFamilyProbe,
        private DoctorFirewallRuleFamilyProbe $firewallRuleFamilyProbe,
        private DoctorToolFamilyProbe $toolFamilyProbe,
        private DoctorScheduleFamilyProbe $scheduleFamilyProbe,
        private DoctorDatabaseConnectionFamilyProbe $databaseConnectionFamilyProbe,
        private DoctorReportSections $reportSections,
        private WorkspacePlacement $workspacePlacement = new WorkspacePlacement,
    ) {}

    /**
     * @param  list<string>  $families
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return array<string, mixed>
     */
    public function probe(
        Node $node,
        array $families = [],
        ?string $key = null,
        ?callable $onFamilyProgress = null,
        ?DoctorTargetScope $scope = null,
    ): array {
        $scope ??= DoctorTargetScope::none();
        $selectedFamilies = $this->selectedFamilies($node, $families);
        $nodeInstances = array_intersect(['app', 'process'], $selectedFamilies) !== []
            ? $this->instancesForNode($node)
            : new Collection;
        $issues = [];

        foreach (self::FAMILY_ORDER as $family) {
            if (! in_array($family, $selectedFamilies, strict: true)) {
                continue;
            }

            $familyIssues = $this->probeFamily(
                family: $family,
                node: $node,
                nodeInstances: $nodeInstances,
                scope: $scope,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
            );
            $issues = [...$issues, ...$familyIssues];
        }

        $issues = $this->filterIssuesByKey($issues, $key);
        $summary = $this->reportSections->summary($issues, []);
        $summary['dispositions'] = $this->reportSections->dispositions($issues);

        return [
            'healthy' => $issues === [],
            'mode' => 'verify',
            'scope' => $this->reportSections->nodeScope($selectedFamilies, $node, $key, $scope),
            'summary' => $summary,
            'issues' => $this->reportSections->serializeIssues($issues),
            'actions' => [],
        ];
    }

    /**
     * @param  list<string>  $families
     * @return list<string>
     */
    private function selectedFamilies(Node $node, array $families): array
    {
        $eligibleFamilies = $this->nodeFamilies->categoriesForNode($node);

        return $families === []
            ? $eligibleFamilies
            : array_values(array_intersect($families, $eligibleFamilies));
    }

    /**
     * @param  Collection<int, Instance>  $nodeInstances
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return list<DoctorIssue>
     */
    private function probeFamily(
        string $family,
        Node $node,
        Collection $nodeInstances,
        DoctorTargetScope $scope,
        ?string $key,
        ?callable $onFamilyProgress,
    ): array {
        return match ($family) {
            'node' => $this->nodeFamilyProbe->probe(
                node: $node,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
            ),
            'app' => $this->appFamilyProbe->probe(
                node: $node,
                nodeInstances: $nodeInstances,
                scope: $scope,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
            ),
            'workspace' => $this->workspaceFamilyProbe->probe(
                node: $node,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
            ),
            'process' => $this->processFamilyProbe->probe(
                node: $node,
                nodeInstances: $nodeInstances,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
            ),
            'proxy' => $this->proxyFamilyProbe->probe(
                node: $node,
                scope: $scope,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
            ),
            'firewall_rule' => $this->firewallRuleFamilyProbe->probe(
                node: $node,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
            ),
            'tool' => $this->toolFamilyProbe->probe(
                node: $node,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
            ),
            'schedule' => $this->scheduleFamilyProbe->probe(
                node: $node,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
            ),
            'database_connection' => $this->databaseConnectionFamilyProbe->probe(
                node: $node,
                scope: $scope,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
            ),
            default => throw new LogicException("Unsupported Doctor family '{$family}'."),
        };
    }

    /**
     * @return Collection<int, Instance>
     */
    private function instancesForNode(Node $node): Collection
    {
        /** @var Builder<Instance> $query */
        $query = Instance::query();

        /** @var Collection<int, Instance> $instances */
        $instances = $query
            ->with(['app.instances'])
            ->orderBy('id')
            ->get();

        /** @var Collection<int, Instance> */
        return $instances
            ->filter(
                fn (Instance $instance): bool => (
                    $this->workspacePlacement->nodeForInstance($instance)?->id === $node->id
                ),
            )
            ->values();
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @return list<DoctorIssue>
     */
    private function filterIssuesByKey(array $issues, ?string $key): array
    {
        if ($key === null) {
            return $issues;
        }

        return array_values(array_filter(
            $issues,
            static fn (DoctorIssue $issue): bool => $issue->key === $key,
        ));
    }
}
