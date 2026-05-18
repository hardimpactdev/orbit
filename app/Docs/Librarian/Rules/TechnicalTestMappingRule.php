<?php

declare(strict_types=1);

namespace App\Docs\Librarian\Rules;

use App\Docs\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class TechnicalTestMappingRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'references';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                foreach ($this->docs->markdownFiles("{$commandDirectory}/technical", recursive: false) as $file) {
                    array_push($findings, ...$this->checkFile($file, file_get_contents($file) ?: ''));
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkFile(string $file, string $contents): array
    {
        if (! str_contains($contents, "\n## Test Mapping")) {
            return [
                $this->finding($file, 'Technical command files must include "## Test Mapping".'),
            ];
        }

        return $this->coverageFindings($file, $contents);
    }

    /**
     * @return list<Finding>
     */
    private function coverageFindings(string $file, string $contents): array
    {
        $findings = [];
        $section = $this->section($contents, 'Test Mapping');
        $coverage = strtolower($section);
        $line = $this->sectionLine($contents, 'Test Mapping');
        $errorCodes = $this->errorCodes($contents);

        if ($errorCodes !== [] && ! $this->mapsErrorCodeCoverage($coverage, $errorCodes)) {
            $findings[] = $this->warning($file, $line, 'Test Mapping must map exhaustive error code coverage or name every documented `error.code` value.');
        }

        if ($this->documentsWarningPayload($contents) && ! $this->mapsWarningPayloadCoverage($coverage)) {
            $findings[] = $this->warning($file, $line, 'Test Mapping must map warning payload coverage when the file documents `success.meta.warnings[]`.');
        }

        if ($this->documentsDestructiveConsent($contents) && ! $this->mapsDestructiveConsentCoverage($coverage)) {
            $findings[] = $this->warning($file, $line, 'Test Mapping must map destructive consent coverage when the file documents destructive consent behavior.');
        }

        return $findings;
    }

    private function section(string $contents, string $heading): string
    {
        if (preg_match('/^## '.preg_quote($heading, '/').'\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches) === 1) {
            return $matches['section'];
        }

        return '';
    }

    private function sectionLine(string $contents, string $heading): ?int
    {
        if (preg_match('/^## '.preg_quote($heading, '/').'\s*$/m', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1;
    }

    /**
     * @return list<string>
     */
    private function errorCodes(string $contents): array
    {
        if (preg_match('/^\|\s*`error\.code`\s*\|\s*(?<codes>.*?)\s*\|/m', $contents, $matches) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $code): string => trim($code, " \t\n\r\0\x0B`"),
            explode(',', $matches['codes']),
        )));
    }

    /**
     * @param  list<string>  $errorCodes
     */
    private function mapsErrorCodeCoverage(string $coverage, array $errorCodes): bool
    {
        if (preg_match('/\b(?:every|all)\s+`?error\.code`?\s+values?\b/', $coverage) === 1) {
            return true;
        }

        if (str_contains($coverage, 'exhaustive') && (str_contains($coverage, 'error.code') || str_contains($coverage, 'error code'))) {
            return true;
        }

        foreach ($errorCodes as $errorCode) {
            if (! str_contains($coverage, strtolower($errorCode))) {
                return false;
            }
        }

        return true;
    }

    private function documentsWarningPayload(string $contents): bool
    {
        return str_contains($contents, 'success.meta.warnings[]')
            || str_contains($contents, 'success.meta.warnings');
    }

    private function mapsWarningPayloadCoverage(string $coverage): bool
    {
        return str_contains($coverage, 'warning')
            && (str_contains($coverage, 'shape') || str_contains($coverage, 'payload'));
    }

    private function documentsDestructiveConsent(string $contents): bool
    {
        $contents = strtolower($contents);

        return str_contains($contents, 'destructive consent')
            || str_contains($contents, 'destructive confirmation');
    }

    private function mapsDestructiveConsentCoverage(string $coverage): bool
    {
        return str_contains($coverage, 'destructive consent')
            || str_contains($coverage, 'confirmation')
            || str_contains($coverage, '--force');
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.technical_test_mapping',
            message: $message,
        );
    }

    private function warning(string $path, ?int $line, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: $line,
            severity: FindingSeverity::Warning,
            rule: 'command_docs.technical_test_mapping',
            message: $message,
        );
    }
}
