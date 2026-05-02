<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class HumanRendererProgressTreeRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.human_progress_tree';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($this->humanRendererFiles($context) as $file) {
            $contents = $context->read($file);

            if (! str_contains($contents, "\n## Progress Tree")) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: 'Human renderer files must include "## Progress Tree".',
                );

                continue;
            }

            foreach ($this->progressTreeBlocks($contents) as $block) {
                $finding = $this->treeStyleFinding($context, $file, $block['text'], $block['line']);

                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function humanRendererFiles(CommandDocsLintContext $context): array
    {
        $files = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                foreach ($context->technicalMarkdownFiles("{$commandDirectory}/technical") as $file) {
                    if (preg_match('/^6\.1_.*_output-render_human\.md$/', basename($file)) === 1) {
                        $files[] = $file;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * @return list<array{text: string, line: int}>
     */
    private function progressTreeBlocks(string $contents): array
    {
        $section = $this->section($contents, 'Progress Tree');

        if ($section === '') {
            return [];
        }

        if (preg_match_all('/```text\s*\R(?<block>.*?)\R```/s', $section, $matches, PREG_OFFSET_CAPTURE) !== false) {
            $blocks = [];
            $sectionOffset = strpos($contents, $section);

            foreach ($matches['block'] as [$block, $offset]) {
                if (! $this->looksLikeProgressTree($block)) {
                    continue;
                }

                $blocks[] = [
                    'text' => $block,
                    'line' => $this->lineForOffset($contents, ($sectionOffset === false ? 0 : $sectionOffset) + $offset),
                ];
            }

            return $blocks;
        }

        return [];
    }

    private function treeStyleFinding(CommandDocsLintContext $context, string $file, string $block, int $line): ?CommandDocsLintFinding
    {
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => rtrim($line), preg_split('/\R/', trim($block)) ?: []),
            static fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            return null;
        }

        if (preg_match('/^\s*(?:[├└][-─━]+|│\s+[├└][-─━]+)/mu', $block) === 1) {
            return $this->finding($context, $file, $line);
        }

        if (preg_match('/\[[A-Z][A-Z _-]*\]/', $block) === 1) {
            return $this->finding($context, $file, $line);
        }

        $treeStartIndex = $this->treeStartIndex($lines);

        if ($treeStartIndex === null) {
            return null;
        }

        $treeEndIndex = $this->treeEndIndex($lines, $treeStartIndex);

        if ($treeEndIndex === null) {
            return $this->finding($context, $file, $line);
        }

        $treeLines = array_slice($lines, $treeStartIndex, $treeEndIndex - $treeStartIndex + 1);
        $firstLine = ltrim($treeLines[0]);
        $lastLine = ltrim($treeLines[array_key_last($treeLines)]);

        if (preg_match('/^┌\s+\S/u', $firstLine) !== 1 || preg_match('/^└\s+\S/u', $lastLine) !== 1) {
            return $this->finding($context, $file, $line);
        }

        foreach (array_slice($treeLines, 1, -1) as $stepLine) {
            $stepLine = ltrim($stepLine);

            if ($stepLine === '│') {
                continue;
            }

            if (preg_match('/^[○◉●]\s+\S/u', $stepLine) === 1) {
                continue;
            }

            return $this->finding($context, $file, $line);
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function treeStartIndex(array $lines): ?int
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:┌|├|└|│|○|◉|●|◌)/u', $line) === 1) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function treeEndIndex(array $lines, int $startIndex): ?int
    {
        foreach (array_slice($lines, $startIndex, preserve_keys: true) as $index => $line) {
            if (preg_match('/^\s*└\s+\S/u', $line) === 1) {
                return $index;
            }
        }

        return null;
    }

    private function looksLikeProgressTree(string $block): bool
    {
        return preg_match('/^[\s]*(?:┌|├|└|│|○|◉|●|◌)/mu', $block) === 1;
    }

    private function section(string $contents, string $heading): string
    {
        if (preg_match('/^## '.preg_quote($heading, '/').'\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches) === 1) {
            return $matches['section'];
        }

        return '';
    }

    private function lineForOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }

    private function finding(CommandDocsLintContext $context, string $file, int $line): CommandDocsLintFinding
    {
        return new CommandDocsLintFinding(
            path: $context->relativePath($file),
            ruleId: $this->id(),
            message: 'Progress tree examples must use the status-dot tree shape: a `┌` title, `○`/`◉`/`●` step lines, optional standalone `│` spacers, and a `└` footer. Do not use branch connectors such as `├─`/`└──` or bracketed status labels such as `[DONE]`.',
            line: $line,
        );
    }
}
