<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class ExitStatusPolicyRule implements GroupedRule
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
                foreach ($this->violations(file_get_contents($file) ?: '') as $line) {
                    $findings[] = new Finding(
                        path: $this->docs->relativePath($file),
                        line: $line,
                        severity: FindingSeverity::Error,
                        rule: 'command_docs.exit_status_policy',
                        message: 'Use the shared exit status policy instead of per-command numeric exit-code sections.',
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<int>
     */
    private function violations(string $contents): array
    {
        $violations = [];
        $patterns = [
            '/^#{2,4}\s+Exit Codes?\s*$/mi',
            '/^\s*-\s*\*\*Exit Codes?\*\*:/mi',
            '/^\s*-\s*\*\*Exit Code:\*\*/mi',
            '/^\s*-\s*`(?:0|1|2|3|4|5|77)`\s*:/m',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $violations[] = $this->lineForOffset($contents, $match[1]);
            }
        }

        return array_values(array_unique($violations));
    }

    private function lineForOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }
}
