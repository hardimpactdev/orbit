<?php

declare(strict_types=1);

namespace App\Docs\Librarian\Rules;

use App\Docs\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class HumanRendererProgressTreeRule implements GroupedRule
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

        foreach ($this->humanRendererFiles() as $file) {
            $contents = file_get_contents($file) ?: '';

            if (! str_contains($contents, "\n## Progress Tree")) {
                $findings[] = new Finding(
                    path: $this->docs->relativePath($file),
                    line: null,
                    severity: FindingSeverity::Error,
                    rule: 'command_docs.human_progress_tree',
                    message: 'Human renderer files must include "## Progress Tree".',
                );

                continue;
            }

            foreach ($this->progressTreeBlocks($contents) as $blockIndex => $block) {
                $finding = $this->treeStyleFinding($file, $block['text'], $block['line']);

                if ($finding !== null) {
                    $findings[] = $finding;

                    continue;
                }

                $finding = $this->productLanguageFinding($file, $block['text'], $block['line']);

                if ($finding !== null) {
                    $findings[] = $finding;

                    continue;
                }

                if ($blockIndex !== 0) {
                    continue;
                }

                $finding = $this->initialLifecycleFinding($file, $block['text'], $block['line']);

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
    private function humanRendererFiles(): array
    {
        $files = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                foreach ($this->docs->markdownFiles("{$commandDirectory}/technical", recursive: false) as $file) {
                    if (preg_match('/^6\.1_.*_output-render_human\.md$/', basename($file)) === 1) {
                        $files[] = $file;
                    }
                }
            }
        }

        sort($files);

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

        if (preg_match_all('/```text\s*\R(?<block>.*?)\R```/s', $section, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $blocks = [];
        $sectionOffset = strpos($contents, $section);

        foreach ($matches['block'] as [$block, $offset]) {
            if (! $this->looksLikeProgressTree($block) && ! $this->containsBracketedStatus($block)) {
                continue;
            }

            $blocks[] = [
                'text' => $block,
                'line' => $this->lineForOffset($contents, ($sectionOffset === false ? 0 : $sectionOffset) + $offset),
            ];
        }

        return $blocks;
    }

    private function treeStyleFinding(string $file, string $block, int $line): ?Finding
    {
        $lines = array_values(array_filter(
            array_map(rtrim(...), preg_split('/\R/', trim($block)) ?: []),
            static fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            return null;
        }

        if (preg_match('/^\s*(?:[├└][-─━]+|│\s+[├└][-─━]+)/mu', $block) === 1) {
            return $this->styleFinding($file, $line);
        }

        if (preg_match('/\[[A-Z][A-Z _-]*\]/', $block) === 1) {
            return $this->styleFinding($file, $line);
        }

        $treeStartIndex = $this->treeStartIndex($lines);

        if ($treeStartIndex === null) {
            return null;
        }

        $treeEndIndex = $this->treeEndIndex($lines, $treeStartIndex);

        if ($treeEndIndex === null) {
            return $this->styleFinding($file, $line);
        }

        $treeLines = array_slice($lines, $treeStartIndex, $treeEndIndex - $treeStartIndex + 1);
        $firstLine = ltrim($treeLines[0]);
        $lastLine = ltrim($treeLines[count($treeLines) - 1]);

        if (preg_match('/^┌\s+\S/u', $firstLine) !== 1 || preg_match('/^└\s+\S/u', $lastLine) !== 1) {
            return $this->styleFinding($file, $line);
        }

        foreach (array_slice($treeLines, 1, -1) as $stepLine) {
            $stepLine = ltrim($stepLine);

            if ($stepLine === '│') {
                continue;
            }

            if (preg_match('/^[○◉●]\s+\S/u', $stepLine) === 1) {
                continue;
            }

            return $this->styleFinding($file, $line);
        }

        return null;
    }

    private function initialLifecycleFinding(string $file, string $block, int $line): ?Finding
    {
        $lines = preg_split('/\R/', trim($block)) ?: [];

        foreach ($lines as $index => $progressLine) {
            if (preg_match('/^\s*┌\s+(?<title>.+)$/u', $progressLine, $matches) !== 1) {
                continue;
            }

            if (preg_match('/^[A-Z][A-Za-z]+ing\b/', $matches['title']) === 1) {
                break;
            }

            return new Finding(
                path: $this->docs->relativePath($file),
                line: $line + $index,
                severity: FindingSeverity::Error,
                rule: 'command_docs.human_progress_tree',
                message: 'The first progress tree must document the initial running state with an active `-ing` title, such as `Updating PHP runtime to PHP 8.5`.',
            );
        }

        foreach ($lines as $index => $progressLine) {
            if (preg_match('/^\s*└\s+(?<footer>.+)$/u', $progressLine, $matches) !== 1) {
                continue;
            }

            if ($matches['footer'] === 'Working...') {
                return null;
            }

            return new Finding(
                path: $this->docs->relativePath($file),
                line: $line + $index,
                severity: FindingSeverity::Error,
                rule: 'command_docs.human_progress_tree',
                message: 'The first progress tree must use `└ Working...` as the pending footer. Completed result footers belong in later success or drift examples.',
            );
        }

        return null;
    }

    private function productLanguageFinding(string $file, string $block, int $line): ?Finding
    {
        foreach (preg_split('/\R/', trim($block)) ?: [] as $index => $progressLine) {
            if (preg_match('/^\s*[○◉●]\s+(?<label>.+)$/u', $progressLine, $matches) !== 1) {
                continue;
            }

            if (! $this->containsImplementationLabel($matches['label'])) {
                continue;
            }

            return new Finding(
                path: $this->docs->relativePath($file),
                line: $line + $index,
                severity: FindingSeverity::Error,
                rule: 'command_docs.human_progress_tree',
                message: 'Progress tree step labels must describe product-level work, not storage or backend implementation. Use labels such as `Apply and verify <change>` instead of `Write gateway intent`, `Write registry intent`, or `Enact runtime artifacts`.',
            );
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

    private function containsBracketedStatus(string $block): bool
    {
        return preg_match('/\[[A-Z][A-Z _-]*\]/', $block) === 1;
    }

    private function containsImplementationLabel(string $label): bool
    {
        return preg_match('/\b(?:write|record|converge|update|remove|removing)\b.*\b(?:intent|registry)\b/i', $label) === 1
            || preg_match('/\benact\s+runtime(?:\/proxy)?\s+artifacts\b/i', $label) === 1
            || preg_match('/\b(?:SQLite|database)\b/i', $label) === 1;
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

    private function styleFinding(string $file, int $line): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($file),
            line: $line,
            severity: FindingSeverity::Error,
            rule: 'command_docs.human_progress_tree',
            message: 'Progress tree examples must use the status-dot tree shape: a `┌` title, `○`/`◉`/`●` step lines, optional standalone `│` spacers, and a `└` footer. Do not use branch connectors such as `├─`/`└──` or bracketed status labels such as `[DONE]`.',
        );
    }
}
