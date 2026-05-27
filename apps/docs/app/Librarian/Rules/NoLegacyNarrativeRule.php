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

        foreach (explode("\n", $contents) as $index => $line) {
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
