<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;
use OrbitDocsLinter\Rules\Prose\DocProfile;
use OrbitDocsLinter\Rules\Prose\MarkdownFileWalker;

/**
 * A long section becomes hard to read when it has no internal structure: no
 * subheadings to navigate, no lists or tables to break monotony, no code blocks
 * to anchor abstract claims, and no discourse markers (`If`, `When`, `By default`,
 * `However`, `For example`) to signal branching or contrast.
 *
 * Laravel's docs use one or more of these escape hatches in nearly every long
 * section. Orbit's deepest spec prose sometimes provides none — five paragraphs
 * of normative declarative sentences in a row. This rule fires only when *all*
 * structural escape hatches are missing, so a marker word is one of several
 * acceptable signals — not a mandatory keyword.
 */
final class LongSectionStructureRule implements CommandDocsLintRule
{
    private const MIN_PARAGRAPHS = 5;

    /**
     * Sentence-initial connectors that count as "the section is teaching, not
     * declaring". Match is case-sensitive at sentence start.
     *
     * @var list<string>
     */
    private const DISCOURSE_MARKERS = [
        'If', 'When', 'By default', 'However', 'Otherwise', 'Instead',
        'For example', 'For instance', 'Sometimes', 'Once', 'Before', 'After',
        'In addition', 'In other words', 'Typically', 'Note that',
    ];

    public function id(): string
    {
        return 'command_docs.long_section_structure';
    }

    public function group(): string
    {
        return 'prose';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach (MarkdownFileWalker::files($context) as $file) {
            if (DocProfile::fromPath($context->relativePath($file)) === DocProfile::Technical) {
                continue;
            }

            $contents = $context->read($file);

            foreach ($this->sections($contents) as $section) {
                if (! $this->shouldFlag($section)) {
                    continue;
                }

                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: sprintf(
                        'Section "%s" is %d paragraphs of flat prose with no subheadings, lists, tables, code, or discourse markers (If/When/However/By default/For example). Split with a subheading, surface an example, or rewrite branching behavior with conditional openers.',
                        $section['heading'],
                        $section['paragraphs'],
                    ),
                    severity: CommandDocsLintSeverity::Warning,
                    line: $section['line'],
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<array{heading: string, line: int, paragraphs: int, has_subheading: bool, has_list: bool, has_table: bool, has_code: bool, has_marker: bool}>
     */
    private function sections(string $contents): array
    {
        $sections = [];
        $current = null;
        $inFence = false;
        $paragraphLines = 0;
        $hadBlank = true;

        $finalize = function () use (&$current, &$sections): void {
            if ($current === null) {
                return;
            }

            $sections[] = $current;
            $current = null;
        };

        foreach (explode("\n", $contents) as $index => $line) {
            $lineNumber = $index + 1;

            if (preg_match('/^\s*```/', $line) === 1) {
                $inFence = ! $inFence;

                if ($current !== null) {
                    $current['has_code'] = true;
                }

                $hadBlank = false;

                continue;
            }

            if ($inFence) {
                if ($current !== null) {
                    $current['has_code'] = true;
                }

                continue;
            }

            if (preg_match('/^(?<level>#{2})\s+(?<heading>.+?)\s*$/', $line, $matches) === 1) {
                $finalize();
                $current = [
                    'heading' => trim($matches['heading']),
                    'line' => $lineNumber,
                    'paragraphs' => 0,
                    'has_subheading' => false,
                    'has_list' => false,
                    'has_table' => false,
                    'has_code' => false,
                    'has_marker' => false,
                ];
                $hadBlank = true;

                continue;
            }

            if (preg_match('/^#{3,6}\s+/', $line) === 1) {
                if ($current !== null) {
                    $current['has_subheading'] = true;
                }

                $hadBlank = true;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^\s*(?:[-*+]|\d+\.)\s+/', $line) === 1) {
                $current['has_list'] = true;
                $hadBlank = false;

                continue;
            }

            if (str_starts_with(trim($line), '|')) {
                $current['has_table'] = true;
                $hadBlank = false;

                continue;
            }

            if (trim($line) === '') {
                $hadBlank = true;

                continue;
            }

            if ($hadBlank) {
                $current['paragraphs']++;
                $hadBlank = false;
            }

            if ($this->startsWithMarker($line)) {
                $current['has_marker'] = true;
            }
        }

        $finalize();

        return $sections;
    }

    /**
     * @param  array{heading: string, line: int, paragraphs: int, has_subheading: bool, has_list: bool, has_table: bool, has_code: bool, has_marker: bool}  $section
     */
    private function shouldFlag(array $section): bool
    {
        if ($section['paragraphs'] < self::MIN_PARAGRAPHS) {
            return false;
        }

        return ! $section['has_subheading']
            && ! $section['has_list']
            && ! $section['has_table']
            && ! $section['has_code']
            && ! $section['has_marker'];
    }

    private function startsWithMarker(string $line): bool
    {
        $trimmed = ltrim($line);

        foreach (self::DISCOURSE_MARKERS as $marker) {
            $next = substr($trimmed, strlen($marker), 1);

            if (! str_starts_with($trimmed, $marker)) {
                continue;
            }

            if ($next === ',' || $next === ' ' || $next === ':' || $next === '') {
                return true;
            }
        }

        return false;
    }
}
