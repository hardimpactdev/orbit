<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class TestMappingFormatRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.test_mapping_format';
    }

    public function group(): string
    {
        return 'references';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                foreach ($context->technicalMarkdownFiles("{$commandDirectory}/technical") as $file) {
                    $contents = $context->read($file);

                    if (! str_contains($contents, "\n## Test Mapping")) {
                        continue;
                    }

                    $section = $this->section($contents, 'Test Mapping');

                    if (! str_contains($section, '| Path | Coverage |')) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($file),
                            ruleId: $this->id(),
                            message: 'Test Mapping must include a "| Path | Coverage |" table.',
                        );

                        continue;
                    }

                    $rows = $this->testRows($section);

                    if ($rows === []) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($file),
                            ruleId: $this->id(),
                            message: 'Test Mapping must include at least one table row with a `tests/...Test.php` path and coverage description.',
                        );

                        continue;
                    }

                    foreach ($rows as $row) {
                        if (! str_starts_with($row['path'], 'tests/')) {
                            $findings[] = new CommandDocsLintFinding(
                                path: $context->relativePath($file),
                                ruleId: $this->id(),
                                message: "Mapped test path must live under tests/: {$row['path']}.",
                            );
                        }

                        if (! str_ends_with($row['path'], 'Test.php')) {
                            $findings[] = new CommandDocsLintFinding(
                                path: $context->relativePath($file),
                                ruleId: $this->id(),
                                message: "Mapped test path must end with Test.php: {$row['path']}.",
                            );
                        }

                        if (str_word_count($row['coverage']) < 5) {
                            $findings[] = new CommandDocsLintFinding(
                                path: $context->relativePath($file),
                                ruleId: $this->id(),
                                message: "Mapped test coverage is too vague for {$row['path']}.",
                            );
                        }
                    }

                    if ($this->containsMissingFileInstruction($section)) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($file),
                            ruleId: $this->id(),
                            message: 'Test Mapping sections must not repeat missing-file process guidance; list the planned test file and coverage only.',
                        );
                    }
                }
            }
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
            if (preg_match('/^\|\s*`(?<path>tests\/[^`]+\.php)`\s*\|\s*(?<coverage>.+?)\s*\|$/', $line, $matches) !== 1) {
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
}
