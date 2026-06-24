<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

/**
 * Terms retired by a product decision must not linger anywhere in the product
 * docs. Each registry entry in `librarian.banned_terms` names the removed
 * terms, the decision that retired them, the replacement wording, and the
 * allow paths that may keep an intentional product-doc mention.
 */
final readonly class BannedTermsRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'references';
    }

    public function check(): array
    {
        $entries = (array) config('librarian.banned_terms', []);

        if ($entries === []) {
            return [];
        }

        $findings = [];

        foreach ($this->docs->markdownFiles($this->docs->docsRoot()) as $file) {
            $documentPath = $this->documentPath($file);
            $lines = null;

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $allowPaths = is_array($entry['allow_paths'] ?? null)
                    ? array_values($entry['allow_paths'])
                    : [];

                if ($this->isAllowed($documentPath, $allowPaths)) {
                    continue;
                }

                $lines ??= explode("\n", file_get_contents($file) ?: '');

                /** @var array{terms?: mixed, decision?: mixed, replacement?: mixed} $entry */
                array_push($findings, ...$this->checkEntry($file, $lines, $entry));
            }
        }

        return $findings;
    }

    /**
     * @param  list<string>  $lines
     * @param  array{terms?: mixed, decision?: mixed, replacement?: mixed}  $entry
     * @return list<Finding>
     */
    private function checkEntry(string $file, array $lines, array $entry): array
    {
        $findings = [];
        $decision = (string) ($entry['decision'] ?? 'a product decision');
        $replacement = (string) ($entry['replacement'] ?? '');

        foreach ((array) ($entry['terms'] ?? []) as $term) {
            $term = (string) $term;

            if ($term === '') {
                continue;
            }

            $pattern = '/(?<![a-z0-9:_-])'.preg_quote($term, '/').'(?![a-z0-9:_-])/i';

            foreach ($lines as $index => $line) {
                if (preg_match($pattern, $line) !== 1) {
                    continue;
                }

                $message = "`{$term}` was retired ({$decision}).";

                if ($replacement !== '') {
                    $message .= " Use {$replacement}.";
                }

                $message .= ' If this mention is intentionally historical, add the file to that entry\'s allow_paths in librarian.banned_terms.';

                $findings[] = new Finding(
                    path: $this->docs->relativePath($file),
                    line: $index + 1,
                    severity: FindingSeverity::Error,
                    rule: 'command_docs.banned_terms',
                    message: $message,
                );
            }
        }

        return $findings;
    }

    /**
     * The document path relative to the docs root, without the canonical
     * `docs/` namespace prefix, for allow-path matching.
     */
    private function documentPath(string $file): string
    {
        $relative = $this->docs->relativePath($file);

        return str_starts_with($relative, 'docs/') ? substr($relative, strlen('docs/')) : $relative;
    }

    /**
     * @param  list<mixed>  $allowPaths
     */
    private function isAllowed(string $documentPath, array $allowPaths): bool
    {
        return array_any(
            $allowPaths,
            static fn (mixed $allowPath): bool => (
                is_string($allowPath)
                && ($documentPath === $allowPath || str_starts_with($documentPath, rtrim($allowPath, '/').'/'))
            ),
        );
    }
}
