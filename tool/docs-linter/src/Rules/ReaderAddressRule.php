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
 * Reader-facing command pages should address the reader directly in sections
 * that describe action: `Usage`, `Examples`, `What Happens`, `Output`, and
 * recovery-style sections. Laravel's docs use second person ("you may", "you
 * should", "you can") roughly once per sentence in these contexts. Orbit's
 * command pages sometimes contain zero `you/your`, which makes them read as
 * specs rather than guidance.
 *
 * This rule is intentionally narrow:
 *   - It only fires on reader-facing pages (technical contracts and concept
 *     glossaries are exempt; their normative third-person voice is correct).
 *   - It only checks named sections where action is expected.
 *   - An imperative-led sentence (Run, Use, Pass, Add, Check, Verify, Configure,
 *     Provide, Confirm, Pick, Choose) counts as direct reader orientation, so
 *     "Run `orbit node:new`" satisfies the rule without `you`.
 */
final class ReaderAddressRule implements CommandDocsLintRule
{
    /**
     * @var list<string>
     */
    private const ACTION_SECTIONS = [
        'usage',
        'examples',
        'example',
        'what happens',
        'output',
        'recovery',
        'getting started',
        'when to use',
    ];

    /**
     * Sentence-initial verbs that count as direct reader orientation. Capitalized
     * because they must appear at sentence start.
     *
     * @var list<string>
     */
    private const IMPERATIVE_OPENERS = [
        'Run', 'Use', 'Pass', 'Add', 'Check', 'Verify', 'Configure',
        'Provide', 'Confirm', 'Pick', 'Choose', 'Set', 'Open',
        'Edit', 'Try', 'Install', 'Restart', 'Stop', 'Start',
        'Apply', 'Remove', 'Inspect', 'Review', 'Read', 'See',
    ];

    public function id(): string
    {
        return 'command_docs.reader_address';
    }

    public function group(): string
    {
        return 'prose';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach (MarkdownFileWalker::files($context) as $file) {
            $relativePath = $context->relativePath($file);

            if (DocProfile::fromPath($relativePath) !== DocProfile::ReaderFacing) {
                continue;
            }

            if (! $this->isCommandPage($relativePath)) {
                continue;
            }

            $contents = $context->read($file);

            foreach ($this->actionSections($contents) as $section) {
                if ($this->hasReaderOrientation($section['body'])) {
                    continue;
                }

                $findings[] = new CommandDocsLintFinding(
                    path: $relativePath,
                    ruleId: $this->id(),
                    message: sprintf(
                        'Section "%s" has no `you`/`your` and no imperative-led sentence. Address the reader directly so the section reads as guidance, not as a spec.',
                        $section['heading'],
                    ),
                    severity: CommandDocsLintSeverity::Warning,
                    line: $section['line'],
                );
            }
        }

        return $findings;
    }

    private function isCommandPage(string $relativePath): bool
    {
        if (! str_contains($relativePath, '/docs/commands/') && ! str_starts_with($relativePath, 'docs/commands/')) {
            return false;
        }

        if (str_contains($relativePath, '/technical/')) {
            return false;
        }

        return str_ends_with($relativePath, '.md');
    }

    /**
     * @return list<array{heading: string, body: string, line: int}>
     */
    private function actionSections(string $contents): array
    {
        $sections = [];
        $current = null;
        $inFence = false;

        foreach (explode("\n", $contents) as $index => $line) {
            $lineNumber = $index + 1;

            if (preg_match('/^\s*```/', $line) === 1) {
                $inFence = ! $inFence;

                if ($current !== null) {
                    $current['body'] .= "\n".$line;
                }

                continue;
            }

            if (! $inFence && preg_match('/^(?<level>#{2,3})\s+(?<heading>.+?)\s*$/', $line, $matches) === 1) {
                if ($current !== null) {
                    $sections[] = $current;
                    $current = null;
                }

                $headingLower = strtolower(trim($matches['heading']));

                if (in_array($headingLower, self::ACTION_SECTIONS, true)) {
                    $current = [
                        'heading' => trim($matches['heading']),
                        'body' => '',
                        'line' => $lineNumber,
                    ];
                }

                continue;
            }

            if ($current !== null) {
                $current['body'] .= "\n".$line;
            }
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        return $sections;
    }

    private function hasReaderOrientation(string $body): bool
    {
        $stripped = preg_replace('/```.*?```/s', '', $body) ?? $body;
        $proseOnly = preg_replace('/^\s*\|.*$/m', '', $stripped) ?? $stripped;

        if (str_word_count(strip_tags($proseOnly)) < 12) {
            return true;
        }

        if (preg_match('/\b(?:you|your)\b/i', $stripped) === 1) {
            return true;
        }

        foreach (self::IMPERATIVE_OPENERS as $verb) {
            if (preg_match('/(?:^|\n|\.\s)'.preg_quote($verb, '/').'\b/', $stripped) === 1) {
                return true;
            }
        }

        return false;
    }
}
