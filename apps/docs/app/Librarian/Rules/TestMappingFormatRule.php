<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class TestMappingFormatRule implements GroupedRule
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
            return [];
        }

        $section = $this->section($contents, 'Test Mapping');

        if (! str_contains($section, '| Path | Coverage |')) {
            return [
                $this->finding($file, 'Test Mapping must include a "| Path | Coverage |" table.'),
            ];
        }

        $rows = $this->testRows($section);

        if ($rows === []) {
            return [
                $this->finding($file, 'Test Mapping must include at least one table row with an `apps/gateway/tests/...Test.php` path and coverage description.'),
            ];
        }

        $findings = [];

        foreach ($rows as $row) {
            if (! str_starts_with($row['path'], 'apps/gateway/tests/')) {
                $findings[] = $this->finding($file, "Mapped test path must live under apps/gateway/tests/: {$row['path']}.");
            }

            if (! str_ends_with($row['path'], 'Test.php')) {
                $findings[] = $this->finding($file, "Mapped test path must end with Test.php: {$row['path']}.");
            }

            if (str_word_count($row['coverage']) < 5) {
                $findings[] = $this->finding($file, "Mapped test coverage is too vague for {$row['path']}.");
            }
        }

        if ($this->containsMissingFileInstruction($section)) {
            $findings[] = $this->finding($file, 'Test Mapping sections must not repeat missing-file process guidance; list the planned test file and coverage only.');
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

    /**
     * @return list<array{path: string, coverage: string}>
     */
    private function testRows(string $section): array
    {
        $rows = [];

        foreach (explode("\n", $section) as $line) {
            if (preg_match('/^\|\s*`(?<path>apps\/gateway\/tests\/[^`]+\.php)`\s*\|\s*(?<coverage>.+?)\s*\|$/', $line, $matches) !== 1) {
                continue;
            }

            $rows[] = [
                'path' => $matches['path'],
                'coverage' => trim($matches['coverage']),
            ];
        }

        return $rows;
    }

    private function containsMissingFileInstruction(string $section): bool
    {
        return str_contains($section, 'create it before changing behavior');
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.test_mapping_format',
            message: $message,
        );
    }
}
