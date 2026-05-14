<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;
use OrbitDocsLinter\Rules\Prose\MarkdownFileWalker;

/**
 * A public command page (the user-facing `.md` directly inside a numbered
 * command directory) must use `##` section headings — `Usage`, `Arguments and
 * options`, `Behavior Summary`, `Requirements`, `Output Summary`, `Examples`,
 * `Related`, and so on. The rest of the prose linter (sentence case, section
 * opener, reader address, long section structure) keys off those headings, so
 * a page that uses only an `H1` plus inline `**Bold:**` labels slips past
 * every other prose rule.
 *
 * The rule fires on two patterns:
 *
 *   - Fewer than two `H2` headings (the page is essentially unstructured).
 *   - Legacy lead-in: an inline `**Purpose:**`, `**Description:**`,
 *     `**Inputs:**`, `**Output:**`, or `**Effects:**` label appears before
 *     the first `H2`. This is the old style; the modern convention puts a
 *     free-form prose paragraph at the top, then `## Usage`.
 */
final class CommandPageStructureRule implements CommandDocsLintRule
{
    private const MIN_SECTIONS = 2;

    /**
     * @var list<string>
     */
    private const LEGACY_LABELS = [
        '**Purpose:**',
        '**Description:**',
        '**Inputs:**',
        '**Output:**',
        '**Effects:**',
    ];

    public function id(): string
    {
        return 'command_docs.command_page_structure';
    }

    public function group(): string
    {
        return 'structure';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach (MarkdownFileWalker::files($context) as $file) {
            $relativePath = $context->relativePath($file);

            if (! $this->isPublicCommandPage($relativePath)) {
                continue;
            }

            $contents = $context->read($file);
            $sectionCount = $this->countSectionHeadings($contents);

            if ($sectionCount < self::MIN_SECTIONS) {
                $findings[] = new CommandDocsLintFinding(
                    path: $relativePath,
                    ruleId: $this->id(),
                    message: sprintf(
                        'Public command page has %d `##` section heading(s). Use the modern section structure (`## Usage`, `## Arguments and options`, `## Behavior Summary`, `## Requirements`, `## Output Summary`, `## Examples`, `## Related`) so prose rules can lint each section.',
                        $sectionCount,
                    ),
                    severity: CommandDocsLintSeverity::Warning,
                    line: 1,
                );

                continue;
            }

            $legacyLabel = $this->findLegacyLeadIn($contents);

            if ($legacyLabel !== null) {
                $findings[] = new CommandDocsLintFinding(
                    path: $relativePath,
                    ruleId: $this->id(),
                    message: sprintf(
                        'Public command page uses the legacy `%s` inline lead-in before the first `##` section. Replace the inline-bold labels with a free-form prose paragraph; the per-field details belong inside `## Arguments and options`, `## Behavior Summary`, and `## Output Summary`.',
                        $legacyLabel,
                    ),
                    severity: CommandDocsLintSeverity::Warning,
                    line: 1,
                );
            }
        }

        return $findings;
    }

    private function isPublicCommandPage(string $relativePath): bool
    {
        if (! preg_match('#^docs/commands/[1-9]\d*_[a-z0-9-]+/[1-9]\d*_[a-z0-9-]+/[^/]+\.md$#', $relativePath)) {
            return false;
        }

        if (str_contains($relativePath, '/technical/')) {
            return false;
        }

        $basename = basename($relativePath, '.md');

        if (in_array($basename, ['README', 'CHANGELOG'], true)) {
            return false;
        }

        return true;
    }

    private function countSectionHeadings(string $contents): int
    {
        $count = 0;
        $inFence = false;

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^\s*```/', $line) === 1) {
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence) {
                continue;
            }

            if (preg_match('/^##\s+\S/', $line) === 1) {
                $count++;
            }
        }

        return $count;
    }

    private function findLegacyLeadIn(string $contents): ?string
    {
        $inFence = false;

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^\s*```/', $line) === 1) {
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence) {
                continue;
            }

            if (preg_match('/^##\s+\S/', $line) === 1) {
                return null;
            }

            $trimmed = ltrim($line);

            foreach (self::LEGACY_LABELS as $label) {
                if (str_starts_with($trimmed, $label)) {
                    return $label;
                }
            }
        }

        return null;
    }
}
