<?php

declare(strict_types=1);

namespace App\Docs\Librarian\Rules;

use App\Docs\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class ConceptIndexRule implements GroupedRule
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
        $conceptsPath = "{$this->docs->repositoryRoot()}/docs/CONCEPTS.md";

        if (! is_file($conceptsPath)) {
            return [];
        }

        $concepts = file_get_contents($conceptsPath) ?: '';
        $findings = [];

        foreach ($this->familyConceptFiles() as $familyDirectory => $conceptFile) {
            array_push($findings, ...$this->checkConceptFile($concepts, $conceptFile, $familyDirectory));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkConceptFile(string $concepts, string $conceptFile, string $familyDirectory): array
    {
        $conceptContents = file_get_contents($conceptFile) ?: '';
        $expectedTerms = $this->definedTerms($conceptContents);
        $relativeConceptFile = $this->docs->relativePath($conceptFile);
        $sectionHeading = $this->sectionHeading($this->docs->familyName($familyDirectory), $conceptContents);

        if ($expectedTerms === []) {
            return [
                $this->finding(
                    $relativeConceptFile,
                    'Family concept documents must define concepts with `- **Term:**` bullets so docs/CONCEPTS.md can index them.',
                ),
            ];
        }

        $section = $this->familySection($concepts, $sectionHeading);

        if ($section === null) {
            return [
                $this->finding(
                    'docs/CONCEPTS.md',
                    "Top-level concepts index must include a `## {$sectionHeading}` section for {$relativeConceptFile}.",
                ),
            ];
        }

        $sourceLink = $this->topLevelConceptSourceLink($conceptFile);
        $findings = [];

        if (! str_contains($section['contents'], "({$sourceLink})")) {
            $findings[] = $this->finding(
                'docs/CONCEPTS.md',
                "Top-level {$sectionHeading} section must link its source as {$sourceLink}.",
                $section['line'],
            );
        }

        $block = $this->indexedBlock($section['contents'], $sourceLink);

        if ($block === null) {
            $findings[] = $this->finding(
                'docs/CONCEPTS.md',
                "Top-level {$sectionHeading} section must contain a concept-index block for {$sourceLink}.",
                $section['line'],
            );

            return $findings;
        }

        $actualTerms = $this->indexedTerms($block);

        if ($actualTerms !== $expectedTerms) {
            $findings[] = $this->finding(
                'docs/CONCEPTS.md',
                "Top-level {$sectionHeading} index must match {$relativeConceptFile}. Expected: {$this->termList($expectedTerms)}. Found: {$this->termList($actualTerms)}.",
                $section['line'],
            );
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function familyConceptFiles(): array
    {
        $conceptFiles = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            $family = $this->docs->familyName($familyDirectory);
            $conceptFile = "{$familyDirectory}/{$family}-concepts.md";

            if (is_file($conceptFile)) {
                $conceptFiles[$familyDirectory] = $conceptFile;
            }
        }

        ksort($conceptFiles);

        return $conceptFiles;
    }

    /**
     * @return list<string>
     */
    private function definedTerms(string $contents): array
    {
        preg_match_all('/^\s*-\s+\*\*(?<term>[^*\n]+)\*\*/m', $contents, $matches);

        return array_values(array_unique(array_map(
            fn (string $term): string => rtrim(trim($term), ':'),
            $matches['term'],
        )));
    }

    /**
     * @return array{contents: string, line: int}|null
     */
    private function familySection(string $contents, string $heading): ?array
    {
        if (preg_match('/^##\s+'.preg_quote($heading, '/').'\s*$/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $match[0][1];
        $afterHeading = $start + strlen($match[0][0]);
        $remaining = substr($contents, $afterHeading);
        $nextHeadingOffset = preg_match('/^##\s+/m', $remaining, $nextMatch, PREG_OFFSET_CAPTURE) === 1
            ? $nextMatch[0][1]
            : strlen($remaining);

        return [
            'contents' => substr($remaining, 0, $nextHeadingOffset),
            'line' => $this->lineForOffset($contents, $start),
        ];
    }

    private function sectionHeading(string $family, string $conceptContents): string
    {
        if (preg_match('/^#\s+(?<heading>.+?)\s*$/m', $conceptContents, $matches) === 1) {
            return trim($matches['heading']);
        }

        return ucwords(str_replace('_', ' ', $family)).' Concepts';
    }

    private function topLevelConceptSourceLink(string $conceptFile): string
    {
        $relativePath = $this->docs->relativePath($conceptFile);

        return preg_replace('/^docs\//', '', $relativePath) ?? $relativePath;
    }

    private function indexedBlock(string $section, string $sourceLink): ?string
    {
        $start = '<!-- concept-index:'.$sourceLink.' -->';
        $end = '<!-- /concept-index -->';
        $startPosition = strpos($section, $start);

        if ($startPosition === false) {
            return null;
        }

        $blockStart = $startPosition + strlen($start);
        $endPosition = strpos($section, $end, $blockStart);

        if ($endPosition === false) {
            return null;
        }

        return substr($section, $blockStart, $endPosition - $blockStart);
    }

    /**
     * @return list<string>
     */
    private function indexedTerms(string $block): array
    {
        preg_match_all('/^\s*-\s+\*\*(?<term>[^*\n]+)\*\*\s*$/m', $block, $matches);

        return array_values(array_map(
            fn (string $term): string => trim($term),
            $matches['term'],
        ));
    }

    /**
     * @param  list<string>  $terms
     */
    private function termList(array $terms): string
    {
        if ($terms === []) {
            return '(none)';
        }

        return implode(', ', array_map(
            fn (string $term): string => "`{$term}`",
            $terms,
        ));
    }

    private function lineForOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }

    private function finding(string $path, string $message, ?int $line = null): Finding
    {
        return new Finding(
            path: $path,
            line: $line,
            severity: FindingSeverity::Error,
            rule: 'command_docs.concept_index',
            message: $message,
        );
    }
}
