<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;
use OrbitDocsLinter\Rules\Prose\MarkdownFileWalker;

/**
 * Title-case headings ("Hub And Spoke", "State Model") read as marketing chrome and
 * make tables of contents visually noisy. Laravel, Stripe, Google, and most modern
 * technical docs use sentence case. This rule flags headings where two or more
 * mid-heading words are capitalized function words.
 *
 * To stay quiet on legitimate proper nouns and acronyms without maintaining a
 * never-finished allowlist, the rule only fires on a small list of function words
 * that have no business being capitalized mid-heading: `And`, `Or`, `The`, `A`,
 * `An`, `To`, `For`, `From`, `With`, `In`, `On`, `Of`, `As`, `By`, `Is`, `Are`, `Be`.
 *
 * Tokens that are all-caps, hyphenated, backticked, or contain digits or path
 * separators are ignored — those are almost certainly acronyms, code identifiers,
 * or compound technical terms that belong as-is.
 */
final class SentenceCaseHeadingRule implements CommandDocsLintRule
{
    /**
     * @var list<string>
     */
    private const FUNCTION_WORDS = [
        'And', 'Or', 'The', 'A', 'An', 'To', 'For', 'From', 'With',
        'In', 'On', 'Of', 'As', 'By', 'Is', 'Are', 'Be',
    ];

    public function id(): string
    {
        return 'command_docs.sentence_case_heading';
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

                if (preg_match('/^(?<level>#{2,4})\s+(?<heading>.+?)\s*$/', $line, $matches) !== 1) {
                    continue;
                }

                $offenders = $this->capitalizedFunctionWords($matches['heading']);

                if ($offenders === []) {
                    continue;
                }

                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: sprintf(
                        'Heading "%s" uses title case (capitalized: %s). Prefer sentence case so headings read like sentences.',
                        trim($matches['heading']),
                        implode(', ', $offenders),
                    ),
                    severity: CommandDocsLintSeverity::Warning,
                    line: $index + 1,
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function capitalizedFunctionWords(string $heading): array
    {
        $tokens = preg_split('/\s+/', trim($heading)) ?: [];
        $offenders = [];

        foreach ($tokens as $position => $token) {
            if ($position === 0) {
                continue;
            }

            if ($this->shouldIgnoreToken($token)) {
                continue;
            }

            if (in_array($token, self::FUNCTION_WORDS, true)) {
                $offenders[] = $token;
            }
        }

        return array_values(array_unique($offenders));
    }

    private function shouldIgnoreToken(string $token): bool
    {
        if ($token === '') {
            return true;
        }

        if (preg_match('/[`\[\]()]/', $token) === 1) {
            return true;
        }

        if (preg_match('/[\/\\\\_]/', $token) === 1) {
            return true;
        }

        if (preg_match('/\d/', $token) === 1) {
            return true;
        }

        if (str_contains($token, '-')) {
            return true;
        }

        $stripped = trim($token, '.,:;!?()');

        if ($stripped === '') {
            return true;
        }

        if (ctype_upper($stripped)) {
            return true;
        }

        return false;
    }
}
