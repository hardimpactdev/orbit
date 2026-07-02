<?php

declare(strict_types=1);

namespace App\Services\Apps\DependencyAudit;

use App\Data\Apps\DependencyAuditParsedResult;
use App\Enums\Apps\DependencyAuditStatus;
use JsonException;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class ComposerDependencyAuditParser
{
    private const int MAX_ADVISORY_SUMMARY = 25;

    public function __construct(
        private DependencyAuditSeverityBands $severityBands,
    ) {}

    public function parse(string $json, int $exitCode): DependencyAuditParsedResult
    {
        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DependencyAuditParseException('malformed_json', 'Composer audit output was not valid JSON.');
        }

        if (! is_array($decoded)) {
            throw new DependencyAuditParseException('malformed_json', 'Composer audit output was not valid JSON.');
        }

        /** @var array<string, mixed> $decoded */
        $severityCounts = [];
        $advisorySummary = [];

        /** @var mixed $advisories */
        $advisories = $decoded['advisories'] ?? [];

        if (is_array($advisories)) {
            array_walk($advisories, function (mixed $packageAdvisories) use (
                &$severityCounts,
                &$advisorySummary,
            ): void {
                if (! is_array($packageAdvisories)) {
                    return;
                }

                array_walk($packageAdvisories, function (mixed $advisory) use (
                    &$severityCounts,
                    &$advisorySummary,
                ): void {
                    if (! is_array($advisory)) {
                        return;
                    }

                    $severity = $this->severityBands->classifyComposerSeverity(
                        is_string($advisory['severity'] ?? null) ? $advisory['severity'] : null,
                    );
                    $this->incrementSeverityCount($severityCounts, $severity);

                    if (count($advisorySummary) < self::MAX_ADVISORY_SUMMARY) {
                        $advisorySummary[] = $this->compactAdvisory($advisory);
                    }
                });
            });
        }

        $bands = $this->severityBands->fromSeverityCounts($severityCounts);
        $hasFindings = $bands['danger'] > 0 || $bands['warning'] > 0;

        $status = match (true) {
            $exitCode === 0 && ! $hasFindings => DependencyAuditStatus::Clean,
            $exitCode === 1 || $hasFindings => DependencyAuditStatus::Findings,
            default => throw new DependencyAuditParseException(
                'audit_command_failed',
                "Composer audit exited with unexpected code {$exitCode}.",
            ),
        };

        return new DependencyAuditParsedResult(
            status: $status,
            dangerCount: $bands['danger'],
            warningCount: $bands['warning'],
            severityCounts: $severityCounts,
            advisorySummary: $advisorySummary,
        );
    }

    /**
     * @param  array<array-key, mixed>  $advisory
     * @return array<string, mixed>
     */
    private function compactAdvisory(array $advisory): array
    {
        return array_filter(
            [
                'advisory_id' => $advisory['advisoryId'] ?? null,
                'package_name' => $advisory['packageName'] ?? null,
                'severity' => $advisory['severity'] ?? null,
                'title' => $advisory['title'] ?? null,
                'cve' => $advisory['cve'] ?? null,
                'link' => $advisory['link'] ?? null,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param  array<string, int>  $severityCounts
     */
    private function incrementSeverityCount(array &$severityCounts, string $severity): void
    {
        if (array_key_exists($severity, $severityCounts)) {
            $severityCounts[$severity]++;

            return;
        }

        $severityCounts[$severity] = 1;
    }
}
