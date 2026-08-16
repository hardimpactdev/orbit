<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

/**
 * Rejects glued long-option value forms such as `--appdocs` after option renames.
 *
 * Valid forms keep the option boundary: `--app=docs` or `--app docs`. Only the
 * short value-taking scope filters are guarded.
 */
final readonly class GluedOptionValueFormRule implements GroupedRule
{
    /**
     * Longest first so multi-word filters win prefix matching.
     *
     * @var list<string>
     */
    private const array OPTIONS = ['workspace', 'instance', 'node', 'app'];

    /**
     * Full tokens that start with a guarded option name but are themselves options.
     *
     * @var list<string>
     */
    private const array ALLOWED_FULL_TOKENS = ['apply'];

    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $pattern = '/(?<![a-zA-Z0-9])--('.implode('|', self::OPTIONS).')([a-z0-9]+)\b/';
        $findings = [];

        foreach ($this->docs->markdownFiles($this->docs->docsRoot()) as $file) {
            foreach (explode("\n", $this->docs->contents($file)) as $lineNumber => $line) {
                $matches = [];
                $matchCount = preg_match_all($pattern, $line, $matches, PREG_SET_ORDER);

                if ($matchCount === false || $matchCount < 1) {
                    continue;
                }

                foreach ($matches as $match) {
                    $option = $match[1];
                    $value = $match[2];
                    $fullToken = $option.$value;

                    if (in_array($fullToken, self::ALLOWED_FULL_TOKENS, strict: true)) {
                        continue;
                    }

                    $findings[] = new Finding(
                        path: $this->docs->relativePath($file),
                        line: $lineNumber + 1,
                        severity: FindingSeverity::Error,
                        rule: 'command_docs.glued_option_value_form',
                        message: "Glued option value form `--{$fullToken}` is invalid after option renames. Use `--{$option}={$value}` (or `--{$option} {$value}`), not a single glued token.",
                    );
                }
            }
        }

        return $findings;
    }
}
