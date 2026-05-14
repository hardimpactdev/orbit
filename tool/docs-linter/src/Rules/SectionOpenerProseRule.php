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
 * Laravel docs always frame a section in one prose sentence before showing code,
 * a table, or a bulleted enumeration. The reader gets the "what is this and when
 * would I use it" before the mechanics. Orbit's command docs sometimes dive
 * straight into a table or a code block, which works for an implementer who
 * already knows the family but fails the new reader.
 *
 * This rule flags an H2 or H3 section whose first non-blank content is a code
 * fence, a table, or a bullet list — with no prose paragraph between the heading
 * and the structural content. It does not require a long intro; one sentence
 * is enough.
 */
final class SectionOpenerProseRule implements CommandDocsLintRule
{
    /**
     * Section headings whose name already tells the reader what to expect.
     * These sections are conventionally "show, don't tell" and a prose intro
     * adds noise, not signal.
     *
     * @var list<string>
     */
    private const SELF_DESCRIBING_HEADINGS = [
        'usage',
        'examples',
        'example',
        'signature',
        'related',
        'see also',
        'arguments',
        'arguments and options',
        'options',
        'requirements',
        'flags',
        'next',
        'next steps',
    ];

    public function id(): string
    {
        return 'command_docs.section_opener_prose';
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

            array_push($findings, ...$this->findingsForFile($context, $file, $contents));
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function findingsForFile(CommandDocsLintContext $context, string $file, string $contents): array
    {
        $findings = [];
        $lines = explode("\n", $contents);
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $line = $lines[$index];

            if (preg_match('/^(?<level>#{2,3})\s+(?<heading>.+?)\s*$/', $line, $matches) !== 1) {
                continue;
            }

            $heading = trim($matches['heading']);

            if (in_array(strtolower($heading), self::SELF_DESCRIBING_HEADINGS, true)) {
                continue;
            }

            $next = $this->firstSubstantiveContent($lines, $index + 1);

            if ($next === null) {
                continue;
            }

            if ($next['kind'] === 'prose' || $next['kind'] === 'subheading') {
                continue;
            }

            $findings[] = new CommandDocsLintFinding(
                path: $context->relativePath($file),
                ruleId: $this->id(),
                message: sprintf(
                    'Section "%s" opens with %s before any prose. Add one prose sentence explaining what this is and when to use it.',
                    $heading,
                    $next['kind'],
                ),
                severity: CommandDocsLintSeverity::Warning,
                line: $index + 1,
            );
        }

        return $findings;
    }

    /**
     * @param  list<string>  $lines
     * @return array{kind: string, line: int}|null
     */
    private function firstSubstantiveContent(array $lines, int $startIndex): ?array
    {
        $count = count($lines);

        for ($index = $startIndex; $index < $count; $index++) {
            $line = $lines[$index];
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^#{2,6}\s+/', $line) === 1) {
                return ['kind' => 'subheading', 'line' => $index + 1];
            }

            if (preg_match('/^\s*```/', $line) === 1) {
                return ['kind' => 'a code block', 'line' => $index + 1];
            }

            if (str_starts_with($trimmed, '|')) {
                return ['kind' => 'a table', 'line' => $index + 1];
            }

            if (preg_match('/^\s*(?:[-*+]|\d+\.)\s+/', $line) === 1) {
                return ['kind' => 'a list', 'line' => $index + 1];
            }

            if (str_starts_with($trimmed, '>')) {
                return ['kind' => 'prose', 'line' => $index + 1];
            }

            if (preg_match('/^\s*<!--/', $line) === 1) {
                continue;
            }

            return ['kind' => 'prose', 'line' => $index + 1];
        }

        return null;
    }
}
