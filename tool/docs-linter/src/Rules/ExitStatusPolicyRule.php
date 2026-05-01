<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class ExitStatusPolicyRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.exit_status_policy';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->markdownFiles($familyDirectory) as $file) {
                foreach ($this->violations($context->read($file)) as $violation) {
                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
                        message: 'Use the shared exit status policy instead of per-command numeric exit-code sections.',
                        line: $violation,
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
            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== false) {
                foreach ($matches[0] as $match) {
                    $violations[] = $this->lineForOffset($contents, $match[1]);
                }
            }
        }

        return array_values(array_unique($violations));
    }

    private function lineForOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }
}
