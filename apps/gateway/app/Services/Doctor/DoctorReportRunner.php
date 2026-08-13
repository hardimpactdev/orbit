<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorRestoreProbe;
use App\Data\Doctor\DoctorRunRequest;
use App\Data\Doctor\DoctorTargetScope;
use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeConvergenceContext;
use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\NodeConverger;
use App\Services\Nodes\NodesProbe;
use Illuminate\Support\Collection;
use LogicException;
use Throwable;

final readonly class DoctorReportRunner
{
    public const int FLEET_PROBE_BATCH_SIZE = DoctorFleetProbeRunner::BATCH_SIZE;

    public function __construct(
        private NodesProbe $nodesProbe,
        private DoctorAppRestorer $appRestorer,
        private DoctorDatabaseConnectionRestorer $databaseConnectionRestorer,
        private DoctorAdoptRunner $adoptRunner,
        private DoctorProcessRestorer $processRestorer,
        private DoctorProxyRestorer $proxyRestorer,
        private DoctorFirewallRuleRestorer $firewallRuleRestorer,
        private DoctorDnsRuntimeRestorer $dnsRuntimeRestorer,
        private NodeConverger $nodeConverger,
        private DoctorScheduleRestorer $scheduleRestorer,
        private DoctorNodeFamilyResolver $nodeFamilies,
        private DoctorNodeProbeRunner $nodeProbeRunner,
        private DoctorFleetProbeRunner $fleetProbeRunner,
        private DoctorFleetTargetProbe $fleetTargetProbe,
        private DoctorProxyRouteInventory $proxyRouteInventory,
        private DnsmasqReconciler $dnsmasqReconciler,
        private DoctorIssueFactory $doctorIssueFactory,
        private DoctorReportSections $reportSections,
        private DoctorWorkspaceRestorer $workspaceRestorer,
        private DoctorOutcomeReconciler $outcomeReconciler,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedFamilies(): array
    {
        return $this->nodeFamilies->supportedFamilies();
    }

    /**
     * @return list<string>
     */
    public function categoriesForRole(string $role): array
    {
        return $this->nodeFamilies->categoriesForRole($role);
    }

    /**
     * @return list<string>
     */
    public function categoriesForNode(Node $node): array
    {
        return $this->nodeFamilies->categoriesForNode($node);
    }

    /**
     * @param  list<string>  $families
     * @return Collection<int, Node>
     */
    public function fleetTargetsForFamilies(array $families = []): Collection
    {
        return $this->fleetProbeRunner->targetsForFamilies($families);
    }

    /**
     * @param  list<string>  $families
     * @param  (callable(Node, 'running'|'done', ?array<string, mixed>=): void)|null  $onNodeProgress
     * @return array<string, mixed>
     */
    public function probeFleet(array $families = [], ?string $key = null, ?callable $onNodeProgress = null): array
    {
        return $this->fleetProbeRunner->probe($families, $key, $onNodeProgress);
    }

    /**
     * @param  list<string>  $families
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return array<string, mixed>
     */
    public function probeFleetTargetReport(
        Node $node,
        array $families,
        ?string $key,
        ?callable $onFamilyProgress = null,
    ): array {
        return $this->fleetTargetProbe->probe(
            node: $node,
            families: $families,
            key: $key,
            onFamilyProgress: $onFamilyProgress,
        );
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    public function run(
        Node $node,
        string $mode = 'verify',
        array $families = [],
        ?DoctorRunRequest $request = null,
    ): array {
        $request ??= DoctorRunRequest::none();
        $scope = $request->targetScope();
        $key = $request->key;
        $dryRun = $request->dryRun;
        $probe = $this->probe($node, $families, $key, scope: $scope);
        $issues = $this->issuesFromProbe($probe);

        if ($mode === 'verify') {
            return $probe;
        }

        if ($dryRun) {
            $plannedIssues = $mode === 'restore'
                ? $this->proxyRestorer->orderForRecovery($issues)
                : $issues;

            return $this->finalize($probe, $mode, $this->plannedActions($mode, $plannedIssues), dryRun: true);
        }

        if ($mode === 'restore') {
            return $this->runRestoreConvergence(
                $node,
                $probe,
                $families,
                new DoctorRunRequest($key, dryRun: false, scope: $scope),
            );
        }

        /** @var list<string> $selectedFamilies */
        $selectedFamilies = $probe['scope']['families'] ?? [];
        $actions = $mode === 'adopt'
            ? (
                $key === 'node.updates'
                    ? []
                    : $this->adoptRunner->adopt(
                        $node,
                        $selectedFamilies,
                        $scope,
                    )
            )
            : $this->applyIssues($node, $mode, $issues);

        if ($mode !== 'adopt' || $key === 'node.updates') {
            $actions = [
                ...$actions,
                ...$this->actionsForUnsupportedMode($mode, $issues, $actions),
            ];
        }

        if ($mode === 'adopt') {
            // No mutation receipts: the first probe is already current. Re-probe only
            // when adopt produced any action (including skipped/unsupported).
            if ($actions === []) {
                return $this->finalize($probe, $mode, $actions);
            }

            return $this->finalizeResolution(
                $node,
                $mode,
                $actions,
                $families,
                new DoctorRunRequest($key, dryRun: false, scope: $scope),
            );
        }

        return $this->finalize($probe, $mode, $actions);
    }

    /**
     * Bounded multi-pass restore: re-apply genuine drift until clean, no progress,
     * or max passes. Scope fences (families/key/instance/workspace) are preserved
     * on every probe and apply.
     *
     * @param  array<string, mixed>  $initialProbe
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    private function runRestoreConvergence(
        Node $node,
        array $initialProbe,
        array $families,
        DoctorRunRequest $request,
    ): array {
        $scope = $request->targetScope();
        $convergence = new DoctorRestoreConvergence;
        // Apply may mutate the node row via a separately loaded model instance
        // (e.g. nodeFromIssue). Re-resolve the selected node from the database
        // for every post-mutation probe/apply so record changes are observed
        // without dropping family/key/instance/workspace fences.
        $resolveSelectedNode = function () use ($node): Node {
            $fresh = Node::query()->find($node->getKey());

            return $fresh instanceof Node ? $fresh : $node;
        };
        $pendingInitialProbe = $this->restoreProbe($initialProbe);
        $probe = function () use (
            $resolveSelectedNode,
            $families,
            $request,
            &$pendingInitialProbe,
        ): DoctorRestoreProbe {
            if ($pendingInitialProbe !== null) {
                $probe = $pendingInitialProbe;
                $pendingInitialProbe = null;

                return $probe;
            }

            $fresh = $this->probe(
                $resolveSelectedNode(),
                $families,
                $request->key,
                scope: $request->targetScope(),
            );

            return $this->restoreProbe($fresh);
        };
        $result = $convergence->run(
            probe: $probe,
            apply: fn (array $issues): array => $this->applyIssues($resolveSelectedNode(), 'restore', $issues),
            isRestorable: fn (DoctorIssue $issue): bool => $this->issueSupportsMode($issue, 'restore'),
        );

        $actions = $result['actions'];
        $finalProbe = $result['probe']->report;
        $finalIssues = $result['probe']->issues;
        $actions = [
            ...$actions,
            ...$this->actionsForUnsupportedMode('restore', $finalIssues, $actions),
        ];

        if ($actions === [] && $result['stop_reason'] === 'no_restorable') {
            return $this->attachConvergenceMetadata(
                report: $this->finalize(
                    probe: $initialProbe,
                    mode: 'restore',
                    actions: $actions,
                    authoritativeObservation: false,
                ),
                passes: 0,
                stopReason: 'no_restorable',
            );
        }

        $annotatedActions = $this->outcomeReconciler->annotateRestoreActionsWithRemainingDrift(
            $actions,
            $finalIssues,
        );

        return $this->attachConvergenceMetadata(
            report: $this->finalize(
                probe: $finalProbe,
                mode: 'restore',
                actions: $annotatedActions,
                authoritativeObservation: true,
            ),
            passes: $result['passes'],
            stopReason: $result['stop_reason'],
        );
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function attachConvergenceMetadata(array $report, int $passes, string $stopReason): array
    {
        $report['convergence'] = [
            'passes' => $passes,
            'stop_reason' => $stopReason,
            'max_passes' => DoctorRestoreConvergence::MAX_PASSES,
        ];
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $report['summary'] = [
            ...$summary,
            'passes' => $passes,
            'stop_reason' => $stopReason,
        ];

        return $report;
    }

    /**
     * Re-probe the selected scope after a real restore/adopt mutation and treat
     * the fresh observation as authoritative for remaining issues and health.
     *
     * @param  list<array<string, mixed>>  $actions
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    public function finalizeResolution(
        Node $node,
        string $mode,
        array $actions,
        array $families = [],
        ?DoctorRunRequest $request = null,
    ): array {
        $request ??= DoctorRunRequest::none();
        $probe = $this->probe(
            $node,
            $families,
            $request->key,
            scope: $request->targetScope(),
        );
        $freshIssues = $this->issuesFromProbe($probe);
        // Fresh observation decides remaining issues for every resolution mode.
        // Richer per-family failure annotations are restore-only and never hide issues.
        $annotatedActions = $mode === 'restore'
            ? $this->outcomeReconciler->annotateRestoreActionsWithRemainingDrift($actions, $freshIssues)
            : $actions;

        return $this->finalize(
            probe: $probe,
            mode: $mode,
            actions: $annotatedActions,
            authoritativeObservation: true,
        );
    }

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
        return $this->nodeProbeRunner->probe(
            node: $node,
            families: $families,
            key: $key,
            onFamilyProgress: $onFamilyProgress,
            scope: $scope,
        );
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @return list<array<string, mixed>>
     */
    public function apply(Node $node, string $mode, array $issues): array
    {
        return $this->applyIssues($node, $mode, $issues);
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @return list<array<string, mixed>>
     */
    private function applyIssues(Node $node, string $mode, array $issues): array
    {
        if ($mode === 'restore') {
            $issues = $this->proxyRestorer->orderForRecovery($issues);
        }

        $actions = [];
        $convergenceRestoreIssues = [];

        foreach ($issues as $issue) {
            if (! $this->issueSupportsMode($issue, $mode)) {
                continue;
            }

            if ($this->productionNodeWorkspaceIssue($node, $issue)) {
                continue;
            }

            if (
                $mode === 'restore'
                && $issue->family === 'tool'
                && $this->dnsRuntimeRestorer->isRestorable($issue->key)
            ) {
                $action = $this->dnsRuntimeRestorer->apply($node, $issue);

                if ($action !== null) {
                    $actions[] = $action;
                }

                continue;
            }

            if (
                $mode === 'restore'
                && DoctorDnsProjectionRestoreSupport::supports($issue->key)
            ) {
                $actions[] = $this->applyDnsProjectionIssue($node, $issue);

                continue;
            }

            if (
                $mode === 'restore'
                && ($issue->family === 'tool'
                || $issue->family === 'node'
                && $issue->key === 'node.role_baseline_mismatch')
            ) {
                $convergenceRestoreIssues[] = $issue;

                continue;
            }

            $action = $this->applyIssue($node, $mode, $issue);

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        if ($convergenceRestoreIssues !== []) {
            $result = $this->nodeConverger->applyIssues(
                $node,
                NodeConvergenceContext::Restore,
                $this->reportSections->serializeIssues($convergenceRestoreIssues),
            );
            $actions = [
                ...$actions,
                ...$result->actions(),
            ];
        }

        return array_map(fn (array $action): array => $this->normalizeActionMode($action, $mode), $actions);
    }

    /**
     * @param  array<string, mixed>  $probe
     * @param  list<array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    public function finalize(
        array $probe,
        string $mode,
        array $actions,
        bool $dryRun = false,
        bool $authoritativeObservation = false,
    ): array {
        $issues = $this->issuesFromProbe($probe);
        $remainingIssues = $authoritativeObservation
            ? $issues
            : $this->outcomeReconciler->remainingIssues($issues, $actions);
        $summary = $this->reportSections->summary($remainingIssues, $actions);
        $summary['dispositions'] = $this->reportSections->dispositions($remainingIssues);

        $result = [
            ...$probe,
            'healthy' =>
                $summary['issues'] === 0
                    && $summary['failed'] === 0
                    && $summary['conflicts'] === 0
                    && $summary['skipped'] === 0,
            'mode' => $mode,
            'summary' => $summary,
            'issues' => $this->reportSections->serializeIssues($remainingIssues),
            'actions' => $actions,
        ];

        if ($dryRun) {
            $result['dry_run'] = true;
        }

        return $result;
    }

    private function productionNodeExcludesWorkspaces(Node $node): bool
    {
        return $this->proxyRouteInventory->nodeExcludesWorkspaces($node);
    }

    private function productionNodeWorkspaceIssue(Node $node, DoctorIssue $issue): bool
    {
        if (! $this->productionNodeExcludesWorkspaces($node)) {
            return false;
        }

        $family = $issue->family;
        $detail = $issue->detail;

        return match ($family) {
            'process' => $this->processRestorer->issueTargetsWorkspace($node, $detail),
            'proxy' => $this->proxyRestorer->issueTargetsWorkspace($detail),
            'database_connection' => $this->databaseConnectionRestorer->issueTargetsWorkspace($detail),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function applyIssue(Node $node, string $mode, DoctorIssue $issue): ?array
    {
        $family = $issue->family;
        $key = $issue->key;
        $detail = $issue->detail;

        return match ($family) {
            'node' => $this->applyNodeIssue($node, $key, $detail, $issue),
            'app' => $this->appRestorer->apply($node, $key, $detail),
            'database_connection' => $this->databaseConnectionRestorer->apply($key, $detail),
            'workspace' => $this->workspaceRestorer->apply($node, $key, $detail),
            'process' => $this->processRestorer->apply($node, $key, $detail),
            'proxy' => $this->proxyRestorer->apply($node, $mode, $key, $detail, $issue),
            'firewall_rule' => $this->firewallRuleRestorer->apply($node, $key, $detail),
            'schedule' => $this->scheduleRestorer->apply($node, $key, $detail, $issue),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function applyNodeIssue(Node $node, string $key, array $detail, DoctorIssue $issue): array
    {
        $targetNode = $this->nodeFromIssue($issue) ?? $node;
        $entry = $this->driftEntryFromStoredParts('node', $key, $detail, $issue);
        $code = $issue->code;

        try {
            $this->nodesProbe->reconcile($targetNode, $entry);
        } catch (Throwable $e) {
            return [
                'family' => 'node',
                'node' => $targetNode->name,
                'code' => $code,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$code}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }

        return [
            'family' => 'node',
            'node' => $targetNode->name,
            'code' => $code,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => $issue->summary !== '' ? $issue->summary : "Fixed {$code}.",
            'details' => $detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function applyDnsProjectionIssue(Node $node, DoctorIssue $issue): array
    {
        $key = $issue->key;
        $family = $key === 'node.dns_mapping_mismatch' ? 'node' : 'proxy';
        $targetNode = $this->nodeFromIssue($issue) ?? $node;
        $detail = $issue->detail;

        if (! DoctorDnsProjectionRestoreSupport::supports($key)) {
            return [
                'family' => $family,
                'node' => $targetNode->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'skipped',
                'summary' => "No restore action is registered for {$key}.",
                'details' => [
                    ...$detail,
                    'reason' => 'mode_not_supported',
                ],
            ];
        }

        if (! $this->dnsmasqReconciler->projectionDirectoryIsMounted()) {
            return [
                'family' => $family,
                'node' => $targetNode->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$key}.",
                'details' => [
                    ...$detail,
                    'error' => 'The live orbit-dns runtime does not consume the managed projection directory.',
                ],
            ];
        }

        try {
            match ($key) {
                'node.dns_mapping_mismatch' => $this->dnsmasqReconciler->reconcileNodeRecords(),
                'proxy.dns_mapping_mismatch' => $this->dnsmasqReconciler->reconcileProxyRecords(),
                default => throw new LogicException("Unsupported DNS projection issue [{$key}]."),
            };
        } catch (Throwable $throwable) {
            return [
                'family' => $family,
                'node' => $targetNode->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$key}.",
                'details' => [
                    ...$detail,
                    'error' => $throwable->getMessage(),
                ],
            ];
        }

        return [
            'family' => $family,
            'node' => $targetNode->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => $issue->summary !== '' ? $issue->summary : "Fixed {$key}.",
            'details' => $detail,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function driftEntryFromStoredParts(
        string $family,
        string $key,
        array $detail,
        ?DoctorIssue $issue = null,
    ): DriftEntry {
        return new DriftEntry(
            family: $family,
            key: $key,
            kind: $issue?->kind ?? DriftKind::Divergent,
            summary: $issue?->summary ?? '',
            detail: $detail,
        );
    }

    private function nodeFromIssue(DoctorIssue $issue): ?Node
    {
        $nodeName = $issue->node;

        if ($nodeName === null) {
            return null;
        }

        $node = Node::query()->where('name', $nodeName)->first();

        return $node instanceof Node ? $node : null;
    }

    private function issueSupportsMode(DoctorIssue $issue, string $mode): bool
    {
        if ($mode === 'adopt') {
            return $issue->adoptable;
        }

        return $issue->restorable;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function normalizeActionMode(array $action, string $mode): array
    {
        if (($action['mode'] ?? null) === 'fix') {
            $action['mode'] = match ($mode) {
                'restore' => 'restore',
                'adopt' => 'adopt',
                default => $mode,
            };
        }

        return $action;
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return list<DoctorIssue>
     */
    private function issuesFromProbe(array $probe): array
    {
        $probeIssues = is_array($probe['issues'] ?? null) ? $probe['issues'] : [];

        return $this->issuesFromValues($probeIssues);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<DoctorIssue>
     */
    private function issuesFromValues(array $values): array
    {
        $issues = [];

        foreach ($values as $value) {
            if ($value instanceof DoctorIssue) {
                $issues[] = $value;
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $issues[] = $this->doctorIssueFactory->fromArray($value);
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function restoreProbe(array $report): DoctorRestoreProbe
    {
        return new DoctorRestoreProbe(
            report: $report,
            issues: $this->issuesFromProbe($report),
        );
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @param  list<array<string, mixed>>  $existingActions
     * @return list<array<string, mixed>>
     */
    private function actionsForUnsupportedMode(string $mode, array $issues, array $existingActions): array
    {
        if ($mode === 'verify') {
            return [];
        }

        $actionIds = array_filter(array_map(
            DoctorIssueResolutionId::forAction(...),
            $existingActions,
        ));

        return array_values(array_map(
            fn (DoctorIssue $issue): array => $this->unsupportedAction($mode, $issue),
            array_filter(
                $issues,
                fn (DoctorIssue $issue): bool => ! in_array(
                    DoctorIssueResolutionId::forIssue($issue),
                    $actionIds,
                    true,
                ),
            ),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function unsupportedAction(string $mode, DoctorIssue $issue): array
    {
        return [
            'family' => $issue->family,
            'node' => $issue->node,
            'code' => $issue->code,
            'key' => $issue->key,
            'mode' => $mode,
            'status' => 'skipped',
            'summary' => "No {$mode} action is registered for {$issue->code}.",
            'details' => [
                'reason' => 'mode_not_supported',
            ],
        ];
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @return list<array<string, mixed>>
     */
    private function plannedActions(string $mode, array $issues): array
    {
        return array_values(array_map(
            fn (DoctorIssue $issue): array => $this->issueSupportsMode($issue, $mode)
                ? $this->plannedAction($mode, $issue)
                : $this->unsupportedAction($mode, $issue),
            $issues,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function plannedAction(string $mode, DoctorIssue $issue): array
    {
        return [
            'family' => $issue->family,
            'node' => $issue->node,
            'code' => $issue->code,
            'key' => $issue->key,
            'mode' => $mode,
            'status' => 'planned',
            'summary' => "Would {$mode} {$issue->code}.",
            'details' => [
                ...$issue->detail,
                'dry_run' => true,
            ],
        ];
    }
}
