<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class NoLegacyNarrativeRule implements GroupedRule
{
    /**
     * @var list<string>
     */
    private const array BANNED_TERMS = [
        'deprecated',
        'do not reintroduce',
        'historical',
        'historically',
        'legacy',
        'no longer',
        'old',
        'predated',
        'predates',
        'previously',
        'retired',
        'superseded',
    ];

    /**
     * Relative subpaths under docs/ that are inherently retrospective and exempt.
     *
     * @var list<string>
     */
    private const array EXEMPT_SUBPATHS = [
        'superpowers/',
    ];

    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'prose';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->markdownFiles($this->docs->docsRoot()) as $file) {
            $relative = $this->docs->relativePath($file);

            if ($this->isExempt($relative)) {
                continue;
            }

            array_push($findings, ...$this->checkFile($file, $relative));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkFile(string $file, string $relative): array
    {
        $contents = file_get_contents($file);

        if ($contents === false || $contents === '') {
            return [];
        }

        $findings = [];

        foreach ($this->proseLines($contents) as $index => $line) {
            foreach (self::BANNED_TERMS as $term) {
                if (preg_match('/\b'.preg_quote($term, '/').'\b/i', $line, $matches) !== 1) {
                    continue;
                }

                $findings[] = new Finding(
                    path: $relative,
                    line: $index + 1,
                    severity: FindingSeverity::Warning,
                    rule: 'command_docs.no_legacy_narrative',
                    message: sprintf(
                        'Legacy-narrative term "%s" in docs. Describe present behavior only — what-was references add noise.',
                        $matches[0],
                    ),
                );
            }
        }

        return $findings;
    }

    /**
     * Lines outside `text`/`json` output-sample fences, keyed by zero-based
     * line index. Fenced output samples document literal renderer output and
     * are part of the output contract, not narrative prose.
     *
     * @return array<int, string>
     */
    private function proseLines(string $contents): array
    {
        $insideOutputFence = false;
        $prose = [];

        foreach (explode("\n", $contents) as $index => $line) {
            $trimmed = ltrim($line);

            if ($insideOutputFence) {
                if (str_starts_with($trimmed, '```')) {
                    $insideOutputFence = false;
                }

                continue;
            }

            if (preg_match('/^```(?:text|json)\b/', $trimmed) === 1) {
                $insideOutputFence = true;

                continue;
            }

            $prose[$index] = $line;
        }

        return $prose;
    }

    private function isExempt(string $relative): bool
    {
        $relativeWithoutDocs = str_starts_with($relative, 'docs/')
            ? substr($relative, strlen('docs/'))
            : $relative;

        foreach (self::EXEMPT_SUBPATHS as $subpath) {
            if (str_starts_with($relativeWithoutDocs, $subpath)) {
                return true;
            }
        }

        return false;
    }
}
