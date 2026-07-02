<?php

declare(strict_types=1);

namespace App\Services\Operations;

use JsonException;

final class WorkloadNodeDoctorIssueParser
{
    public function fromOutput(string $output): ?int
    {
        $output = trim($output);

        if ($output === '') {
            return null;
        }

        $issues = null;

        foreach (explode("\n", $output) as $line) {
            $lineIssues = $this->issuesFromLine($line);

            if ($lineIssues !== null) {
                $issues = $lineIssues;
            }
        }

        return $issues;
    }

    private function issuesFromLine(string $line): ?int
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        try {
            $decoded = json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        return $this->issuesFromDecoded($decoded);
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     */
    private function issuesFromDecoded(array $decoded): ?int
    {
        foreach ($this->issuePaths() as $path) {
            $issues = data_get($decoded, $path);

            if (is_int($issues)) {
                return $issues;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function issuePaths(): array
    {
        return [
            'success.data.doctor.summary.issues',
            'error.data.doctor.summary.issues',
            'data.doctor.summary.issues',
        ];
    }
}
