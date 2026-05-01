<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class ConceptIndexRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.concept_index';
    }

    public function group(): string
    {
        return 'references';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $conceptsPath = "{$context->repositoryRoot}/docs/CONCEPTS.md";

        if (! is_file($conceptsPath)) {
            return [];
        }

        $concepts = $context->read($conceptsPath);
        $findings = [];

        foreach ($this->familyConceptFiles($context) as $familyDirectory => $conceptFile) {
            $expectedTerms = $this->definedTerms($context->read($conceptFile));
            $relativeConceptFile = $context->relativePath($conceptFile);
            $family = preg_replace('/^[1-9]\d*_/', '', basename($familyDirectory)) ?? basename($familyDirectory);

            if ($expectedTerms === []) {
                $findings[] = new CommandDocsLintFinding(
                    path: $relativeConceptFile,
                    ruleId: $this->id(),
                    message: 'Family concept documents must define concepts with `- **Term:**` bullets so docs/CONCEPTS.md can index them.',
                );

                continue;
            }

            $section = $this->familySection($concepts, $family);

            if ($section === null) {
                $findings[] = new CommandDocsLintFinding(
                    path: 'docs/CONCEPTS.md',
                    ruleId: $this->id(),
                    message: "Top-level concepts index must include a `## {$this->sectionHeading($family)}` section for {$relativeConceptFile}.",
                );

                continue;
            }

            $sourceLink = $this->topLevelConceptSourceLink($context, $conceptFile);

            if (! str_contains($section['contents'], "({$sourceLink})")) {
                $findings[] = new CommandDocsLintFinding(
                    path: 'docs/CONCEPTS.md',
                    ruleId: $this->id(),
                    message: "Top-level {$this->sectionHeading($family)} section must link its source as {$sourceLink}.",
                    line: $section['line'],
                );
            }

            $block = $this->indexedBlock($section['contents'], $sourceLink);

            if ($block === null) {
                $findings[] = new CommandDocsLintFinding(
                    path: 'docs/CONCEPTS.md',
                    ruleId: $this->id(),
                    message: "Top-level {$this->sectionHeading($family)} section must contain a concept-index block for {$sourceLink}.",
                    line: $section['line'],
                );

                continue;
            }

            $actualTerms = $this->indexedTerms($block);

            if ($actualTerms !== $expectedTerms) {
                $findings[] = new CommandDocsLintFinding(
                    path: 'docs/CONCEPTS.md',
                    ruleId: $this->id(),
                    message: "Top-level {$this->sectionHeading($family)} index must match {$relativeConceptFile}. Expected: {$this->termList($expectedTerms)}. Found: {$this->termList($actualTerms)}.",
                    line: $section['line'],
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function familyConceptFiles(CommandDocsLintContext $context): array
    {
        $familyDirectories = $context->convertedFamilyDirectories();

        if ($familyDirectories === [] && $this->isTopLevelConceptsPath($context)) {
            $familyDirectories = $this->allConvertedFamilyDirectories($context);
        }

        $conceptFiles = [];

        foreach ($familyDirectories as $familyDirectory) {
            $family = preg_replace('/^[1-9]\d*_/', '', basename($familyDirectory)) ?? basename($familyDirectory);
            $conceptFile = "{$familyDirectory}/{$family}-concepts.md";

            if (is_file($conceptFile)) {
                $conceptFiles[$familyDirectory] = $conceptFile;
            }
        }

        ksort($conceptFiles);

        return $conceptFiles;
    }

    private function isTopLevelConceptsPath(CommandDocsLintContext $context): bool
    {
        return rtrim($context->scanRoot, '/') === "{$context->repositoryRoot}/docs/CONCEPTS.md";
    }

    /**
     * @return list<string>
     */
    private function allConvertedFamilyDirectories(CommandDocsLintContext $context): array
    {
        $commandsDirectory = "{$context->repositoryRoot}/docs/commands";
        $directories = [];

        foreach (scandir($commandsDirectory) ?: [] as $entry) {
            $path = "{$commandsDirectory}/{$entry}";

            if ($context->isConvertedFamilyDirectory($path)) {
                $directories[] = $path;
            }
        }

        sort($directories);

        return $directories;
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
    private function familySection(string $contents, string $family): ?array
    {
        $heading = $this->sectionHeading($family);

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

    private function sectionHeading(string $family): string
    {
        return ucwords(str_replace('_', ' ', $family)).' Concepts';
    }

    private function topLevelConceptSourceLink(CommandDocsLintContext $context, string $conceptFile): string
    {
        $relativePath = $context->relativePath($conceptFile);

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
}
