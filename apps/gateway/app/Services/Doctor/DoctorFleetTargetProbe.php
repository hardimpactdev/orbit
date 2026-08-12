<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorTargetScope;
use App\Enums\DriftKind;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;

final readonly class DoctorFleetTargetProbe
{
    public function __construct(
        private DoctorNodeProbeRunner $nodeProbeRunner,
        private DoctorNodeFamilyResolver $nodeFamilies,
        private DoctorIssueFactory $doctorIssueFactory,
        private DoctorReportSections $reportSections,
    ) {}

    /**
     * @param  list<string>  $families
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return array<string, mixed>
     */
    public function probe(
        Node $node,
        array $families,
        ?string $key,
        ?callable $onFamilyProgress = null,
    ): array {
        try {
            return $this->nodeProbeRunner->probe(
                node: $node,
                families: $families,
                key: $key,
                onFamilyProgress: $onFamilyProgress,
            );
        } catch (RemoteShellFailed $exception) {
            return $this->remoteShellFailedReport($node, $families, $key, $exception);
        } catch (RemoteLocalExecutorTransportFailed $exception) {
            return $this->localExecutorFailedReport($node, $families, $key, $exception);
        }
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    private function remoteShellFailedReport(
        Node $node,
        array $families,
        ?string $key,
        RemoteShellFailed $exception,
    ): array {
        $selectedFamilies = $this->selectedFamilies($node, $families);
        $issue = $this->doctorIssueFactory->fromProbeFailure(
            family: 'node',
            node: $node->name,
            key: 'node.remote_shell_probe_failed',
            exception: $exception,
            summary: "Doctor probe failed on node '{$node->name}': {$exception->getMessage()}",
        );
        $issues = $this->filterIssuesByKey([$issue], $key);
        $summary = $this->reportSections->summary($issues, []);
        $summary['dispositions'] = $this->reportSections->dispositions($issues);

        return [
            'healthy' => false,
            'mode' => 'verify',
            'scope' => $this->reportSections->nodeScope(
                $selectedFamilies,
                $node,
                $key,
                DoctorTargetScope::none(),
            ),
            'summary' => $summary,
            'issues' => $this->reportSections->serializeIssues($issues),
            'actions' => [],
        ];
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    private function localExecutorFailedReport(
        Node $node,
        array $families,
        ?string $key,
        RemoteLocalExecutorTransportFailed $exception,
    ): array {
        $selectedFamilies = $this->selectedFamilies($node, $families);
        $issue = $this->doctorIssueFactory->fromArray([
            'family' => 'node',
            'node' => $node->name,
            'key' => 'node.local_executor_probe_failed',
            'kind' => DriftKind::Unverifiable->value,
            'summary' => "Doctor probe failed on node '{$node->name}': {$exception->getMessage()}",
            'detail' => [
                'error' => $exception->getMessage(),
            ],
        ]);
        $issues = $this->filterIssuesByKey([$issue], $key);
        $summary = $this->reportSections->summary($issues, []);

        return [
            'healthy' => false,
            'mode' => 'verify',
            'scope' => $this->reportSections->nodeScope(
                $selectedFamilies,
                $node,
                $key,
                DoctorTargetScope::none(),
            ),
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
