<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Node;
use Illuminate\Support\Collection;

final readonly class DoctorFleetNodeProjection
{
    public function __construct(
        private DoctorReportSections $reportSections,
    ) {}

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function nodeSummary(Node $node, array $report): array
    {
        $reportSummary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $reportScope = is_array($report['scope'] ?? null) ? $report['scope'] : [];

        return [
            'node' => $node->name,
            'role' => $node->displayRole(),
            'roles' => $this->reportSections->roles($node),
            'healthy' => ($report['healthy'] ?? false) === true,
            'families' => is_array($reportScope['families'] ?? null) ? $reportScope['families'] : [],
            'summary' => $reportSummary,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    public function nodeIssues(array $report): array
    {
        $reportIssues = is_array($report['issues'] ?? null) ? $report['issues'] : [];

        /** @var list<array<string, mixed>> */
        return array_values(array_filter($reportIssues, is_array(...)));
    }

    /**
     * @param  Collection<int, Node>  $targets
     * @param  array<int, array<string, mixed>>  $nodesByIndex
     * @return list<array<string, mixed>>
     */
    public function orderedNodeSummaries(Collection $targets, array $nodesByIndex): array
    {
        /** @var list<array<string, mixed>> */
        return $targets
            ->values()
            ->map(static fn (Node $_target, int $index): ?array => $nodesByIndex[$index] ?? null)
            ->filter(static fn (?array $node): bool => $node !== null)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Node>  $targets
     * @param  array<int, list<array<string, mixed>>>  $issuesByIndex
     * @return list<array<string, mixed>>
     */
    public function orderedIssues(Collection $targets, array $issuesByIndex): array
    {
        /** @var list<array<string, mixed>> */
        return $targets
            ->values()
            ->flatMap(static fn (Node $_target, int $index): array => $issuesByIndex[$index] ?? [])
            ->values()
            ->all();
    }
}
