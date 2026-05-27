<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class DriftIssueSuffixRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->markdownFiles($familyDirectory) as $file) {
                foreach ($this->orphanedCodes(file_get_contents($file) ?: '') as $code) {
                    $replacement = preg_replace('/_orphaned$/', '_extra', $code) ?? $code;

                    $findings[] = new Finding(
                        path: $this->docs->relativePath($file),
                        line: null,
                        severity: FindingSeverity::Error,
                        rule: 'command_docs.drift_issue_suffix',
                        message: "Drift issue code `{$code}` must use `_extra` suffix (`{$replacement}`), matching DriftKind::Extra.",
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function orphanedCodes(string $contents): array
    {
        preg_match_all(
            '/(?<![a-z0-9_.-])(?<code>[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*_orphaned)(?![a-z0-9_.-])/i',
            $contents,
            $matches,
        );

        $codes = array_values(array_unique($matches['code']));
        sort($codes);

        return $codes;
    }
}
