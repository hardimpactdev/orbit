<?php

declare(strict_types=1);

namespace App\Services\Apps\DependencyAudit;

use App\Data\Apps\DependencyAuditParsedResult;
use App\Enums\Apps\DependencyAuditStatus;
use JsonException;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class NpmDependencyAuditParser
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
            throw new DependencyAuditParseException('malformed_json', 'npm audit output was not valid JSON.');
        }

        if (! is_array($decoded)) {
            throw new DependencyAuditParseException('malformed_json', 'npm audit output was not valid JSON.');
        }

        /** @var array<string, mixed> $decoded */
        $severityCounts = $this->metadataSeverityCounts($decoded);
        $advisorySummary = $this->compactVulnerabilities($decoded);

        if ($severityCounts === []) {
            $severityCounts = $this->severityCountsFromVulnerabilities($decoded);
        }

        $bands = $this->severityBands->fromSeverityCounts($severityCounts);
        $hasFindings = $bands['danger'] > 0 || $bands['warning'] > 0;

        $status = match (true) {
            $exitCode === 0 && ! $hasFindings => DependencyAuditStatus::Clean,
            $exitCode === 1 || $hasFindings => DependencyAuditStatus::Findings,
            default => throw new DependencyAuditParseException(
                'audit_command_failed',
                "npm audit exited with unexpected code {$exitCode}.",
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
     * @param  array<array-key, mixed>  $decoded
     * @return array<string, int>
     */
    private function metadataSeverityCounts(array $decoded): array
    {
        /** @var mixed $metadata */
        $metadata = $decoded['metadata'] ?? null;

        if (! is_array($metadata)) {
            return [];
        }

        /** @var mixed $vulnerabilities */
        $vulnerabilities = $metadata['vulnerabilities'] ?? null;

        if (! is_array($vulnerabilities)) {
            return [];
        }

        $counts = [];

        array_walk($vulnerabilities, static function (mixed $count, mixed $severity) use (&$counts): void {
            if (! is_string($severity) || ! is_int($count)) {
                return;
            }

            $counts[mb_strtolower($severity)] = $count;
        });

        return $counts;
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return array<string, int>
     */
    private function severityCountsFromVulnerabilities(array $decoded): array
    {
        /** @var mixed $vulnerabilities */
        $vulnerabilities = $decoded['vulnerabilities'] ?? null;

        if (! is_array($vulnerabilities)) {
            return [];
        }

        $counts = [];

        array_walk($vulnerabilities, function (mixed $vulnerability) use (&$counts): void {
            if (! is_array($vulnerability)) {
                return;
            }

            $severity = mb_strtolower((string) ($vulnerability['severity'] ?? 'unknown'));
            $this->incrementSeverityCount($counts, $severity);
        });

        return $counts;
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return list<array<string, mixed>>
     */
    private function compactVulnerabilities(array $decoded): array
    {
        /** @var mixed $vulnerabilities */
        $vulnerabilities = $decoded['vulnerabilities'] ?? null;

        if (! is_array($vulnerabilities)) {
            return [];
        }

        $summary = [];

        array_walk($vulnerabilities, function (mixed $vulnerability) use (&$summary): void {
            if (! is_array($vulnerability) || count($summary) >= self::MAX_ADVISORY_SUMMARY) {
                return;
            }

            $summary[] = array_filter(
                [
                    'name' => $vulnerability['name'] ?? null,
                    'severity' => $vulnerability['severity'] ?? null,
                    'is_direct' => $vulnerability['isDirect'] ?? null,
                    'range' => $vulnerability['range'] ?? null,
                    'fix_available' => $vulnerability['fixAvailable'] ?? null,
                    'via' => $this->compactVia($vulnerability['via'] ?? null),
                ],
                static fn (mixed $value): bool => $value !== null && $value !== [],
            );
        });

        return $summary;
    }

    /**
     * @return list<string|array<string, mixed>>
     */
    private function compactVia(mixed $via): array
    {
        if (! is_array($via)) {
            return [];
        }

        $compact = [];

        array_walk($via, static function (mixed $entry) use (&$compact): void {
            if (is_string($entry)) {
                $compact[] = $entry;

                return;
            }

            if (! is_array($entry)) {
                return;
            }

            $compact[] = array_filter(
                [
                    'source' => $entry['source'] ?? null,
                    'name' => $entry['name'] ?? null,
                    'severity' => $entry['severity'] ?? null,
                    'title' => $entry['title'] ?? null,
                ],
                static fn (mixed $value): bool => $value !== null && $value !== '',
            );
        });

        return $compact;
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
