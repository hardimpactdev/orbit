<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;
use OrbitDocsLinter\Rules\Prose\MarkdownFileWalker;
use OrbitDocsLinter\Rules\Prose\ProseSegmenter;

/**
 * `DocumentComplexityRule` skips any line starting with `|`, so prose hidden in
 * markdown table cells is invisible to every prose rule. This is a blind spot:
 * Orbit's command docs often park multi-sentence guidance inside a `| Behavior |
 * Description |` table, where it escapes scrutiny while reading just as densely
 * as a paragraph would.
 *
 * This rule walks table cells separately and warns when a single cell exceeds 30
 * words or contains 3+ sentences. Header rows and separator rows are skipped.
 */
final class TableProseComplexityRule implements CommandDocsLintRule
{
    private const MAX_CELL_WORDS = 30;

    private const MAX_CELL_SENTENCES = 3;

    public function id(): string
    {
        return 'command_docs.table_prose_complexity';
    }

    public function group(): string
    {
        return 'prose';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach (MarkdownFileWalker::files($context) as $file) {
            $contents = $context->read($file);
            $inFence = false;

            foreach (explode("\n", $contents) as $index => $line) {
                if (preg_match('/^\s*```/', $line) === 1) {
                    $inFence = ! $inFence;

                    continue;
                }

                if ($inFence) {
                    continue;
                }

                if (! ProseSegmenter::isTableLine($line) || ProseSegmenter::isTableSeparator($line)) {
                    continue;
                }

                foreach (ProseSegmenter::tableCellTexts($line) as $cell) {
                    $words = ProseSegmenter::words($cell);
                    $sentences = ProseSegmenter::sentences($cell);

                    if (count($words) > self::MAX_CELL_WORDS) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($file),
                            ruleId: $this->id(),
                            message: sprintf(
                                'Table cell has %d prose words, above threshold %d. Split the cell, move guidance to surrounding prose, or use a nested list.',
                                count($words),
                                self::MAX_CELL_WORDS,
                            ),
                            severity: CommandDocsLintSeverity::Warning,
                            line: $index + 1,
                        );
                    }

                    if (count($sentences) > self::MAX_CELL_SENTENCES) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($file),
                            ruleId: $this->id(),
                            message: sprintf(
                                'Table cell has %d sentences, above threshold %d. Move long guidance out of the table.',
                                count($sentences),
                                self::MAX_CELL_SENTENCES,
                            ),
                            severity: CommandDocsLintSeverity::Warning,
                            line: $index + 1,
                        );
                    }
                }
            }
        }

        return $findings;
    }
}
