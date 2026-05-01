<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;

final class BehaviorContractStructureRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.behavior_contract_structure';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory));
                $file = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($file)) {
                    continue;
                }

                $contents = $context->read($file);
                $section = $this->section($contents, 'Behavior Contract');
                $headings = $this->headings($section);

                if ($section === '') {
                    continue;
                }

                $placeholderHeadings = array_values(array_filter(
                    $headings,
                    fn (string $heading): bool => $this->isPlaceholderHeading($heading),
                ));

                $meaningfulHeadings = array_values(array_filter(
                    $headings,
                    fn (string $heading): bool => ! $this->isPlaceholderHeading($heading)
                        && ! $this->isBoundaryHeading($heading),
                ));

                if ($headings === []) {
                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
                        message: 'Behavior Contract must use meaningful command-specific level-3 subsections instead of a flat list.',
                        severity: CommandDocsLintSeverity::Warning,
                        line: $this->sectionLine($contents, 'Behavior Contract'),
                    );

                    continue;
                }

                if ($placeholderHeadings !== [] || $meaningfulHeadings === []) {
                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
                        message: 'Behavior Contract must use meaningful command-specific subsections. Replace placeholder headings such as `Core Behavior`; `Scope Boundaries` is allowed only alongside concrete behavior/rule sections.',
                        severity: CommandDocsLintSeverity::Warning,
                        line: $this->sectionLine($contents, 'Behavior Contract'),
                    );
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
     * @return list<string>
     */
    private function headings(string $section): array
    {
        preg_match_all('/^###\s+(?<heading>.+?)\s*$/m', $section, $matches);

        return array_map(
            fn (string $heading): string => trim($heading),
            $matches['heading'] ?? [],
        );
    }

    private function isPlaceholderHeading(string $heading): bool
    {
        return in_array($this->normalizeHeading($heading), [
            'behavior',
            'command behavior',
            'command rules',
            'core behavior',
            'general behavior',
            'general rules',
            'rules',
        ], true);
    }

    private function isBoundaryHeading(string $heading): bool
    {
        return in_array($this->normalizeHeading($heading), [
            'boundaries',
            'constraints',
            'exclusions',
            'non-goals',
            'out of scope',
            'scope',
            'scope boundaries',
        ], true);
    }

    private function normalizeHeading(string $heading): string
    {
        return strtolower(trim(str_replace('`', '', $heading)));
    }

    private function sectionLine(string $contents, string $heading): ?int
    {
        if (preg_match('/^## '.preg_quote($heading, '/').'\s*$/m', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1;
    }
}
