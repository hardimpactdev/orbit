<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class TestMappingPathExistsRule implements GroupedRule
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
            foreach ($this->docs->markdownFiles($familyDirectory) as $file) {
                $contents = file_get_contents($file);

                array_push($findings, ...$this->checkFile($file, $contents === false ? '' : $contents));
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

        $findings = [];
        $repoRoot = $this->repoRoot();
        $section = $this->section($contents, 'Test Mapping');
        $sectionLine = $this->sectionLine($contents, 'Test Mapping') ?? 1;

        foreach (explode("\n", $section) as $offset => $line) {
            foreach ($this->mappedTestPaths($line) as $path) {
                if (is_file("{$repoRoot}/{$path}")) {
                    continue;
                }

                $findings[] = new Finding(
                    path: $this->docs->relativePath($file),
                    line: $sectionLine + $offset + 1,
                    severity: FindingSeverity::Error,
                    rule: 'command_docs.test_mapping_path_exists',
                    message: "Mapped test path does not exist in the repository: {$path}.",
                );
            }
        }

        return $findings;
    }

    private function repoRoot(): string
    {
        $repoRoot = realpath(base_path('../..'));

        return $repoRoot === false ? base_path('../..') : $repoRoot;
    }

    private function section(string $contents, string $heading): string
    {
        /** @var array{section?: string} $matches */
        $matches = [];

        if (
            preg_match(
                '/^## '.preg_quote($heading, delimiter: '/').'\s*$(?<section>.*?)(?:^## |\z)/ms',
                $contents,
                $matches,
            ) === 1
        ) {
            return $matches['section'] ?? '';
        }

        return '';
    }

    private function sectionLine(string $contents, string $heading): ?int
    {
        /** @var array{0?: array{0: string, 1: int}} $matches */
        $matches = [];

        if (
            preg_match('/^## '.preg_quote($heading, delimiter: '/').'\s*$/m', $contents, $matches, PREG_OFFSET_CAPTURE)
            !== 1
        ) {
            return null;
        }

        return substr_count(substr($contents, offset: 0, length: (int) $matches[0][1]), needle: "\n") + 1;
    }

    /**
     * @return list<string>
     */
    private function mappedTestPaths(string $line): array
    {
        if (! str_starts_with(ltrim($line), '|')) {
            return [];
        }

        /** @var array{1?: list<string>} $matches */
        $matches = [];

        preg_match_all(
            '#`((?:apps/(?:cli|gateway|docs|e2e|reverb)/tests|packages/(?:core|sdk)/tests)/[^`]+Test\.php)`#',
            $line,
            $matches,
        );

        return array_values(array_filter($matches[1] ?? [], is_string(...)));
    }
}
