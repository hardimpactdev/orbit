<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class BehaviorContractStructureRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = $this->docs->commandName($commandDirectory);
                $file = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($file)) {
                    continue;
                }

                array_push($findings, ...$this->checkFile($file, file_get_contents($file) ?: ''));
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkFile(string $file, string $contents): array
    {
        $section = $this->section($contents, 'Behavior Contract');
        $headings = $this->headings($section);

        if ($section === '') {
            return [];
        }

        $placeholderHeadings = array_values(array_filter(
            $headings,
            $this->isPlaceholderHeading(...),
        ));

        $meaningfulHeadings = array_values(array_filter(
            $headings,
            fn (string $heading): bool => (
                ! $this->isPlaceholderHeading($heading) && ! $this->isBoundaryHeading($heading)
            ),
        ));

        if ($headings === []) {
            return [
                $this->finding(
                    $file,
                    'Behavior Contract must use meaningful command-specific level-3 subsections instead of a flat list.',
                    $this->sectionLine($contents, 'Behavior Contract'),
                ),
            ];
        }

        if ($placeholderHeadings === [] && $meaningfulHeadings !== []) {
            return [];
        }

        return [
            $this->finding(
                $file,
                'Behavior Contract must use meaningful command-specific subsections. Replace placeholder headings such as `Core Behavior`; `Scope Boundaries` is allowed only alongside concrete behavior/rule sections.',
                $this->sectionLine($contents, 'Behavior Contract'),
            ),
        ];
    }

    private function section(string $contents, string $heading): string
    {
        if (
            preg_match('/^## '.preg_quote($heading, '/').'\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches) === 1
        ) {
            return $matches['section'];
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function headings(string $section): array
    {
        preg_match_all('/^###\s+(?<heading>.+?)\s*$/m', $section, $matches, PREG_SET_ORDER);

        return array_values(array_map(
            trim(...),
            array_filter(array_column($matches, 'heading'), is_string(...)),
        ));
    }

    private function isPlaceholderHeading(string $heading): bool
    {
        return in_array(
            $this->normalizeHeading($heading),
            [
                'behavior',
                'command behavior',
                'command rules',
                'core behavior',
                'general behavior',
                'general rules',
                'rules',
            ],
            true,
        );
    }

    private function isBoundaryHeading(string $heading): bool
    {
        return in_array(
            $this->normalizeHeading($heading),
            [
                'boundaries',
                'constraints',
                'exclusions',
                'non-goals',
                'out of scope',
                'scope',
                'scope boundaries',
            ],
            true,
        );
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

        return substr_count(substr($contents, 0, (int) $matches[0][1]), "\n") + 1;
    }

    private function finding(string $path, string $message, ?int $line): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: $line,
            severity: FindingSeverity::Warning,
            rule: 'command_docs.behavior_contract_structure',
            message: $message,
        );
    }
}
