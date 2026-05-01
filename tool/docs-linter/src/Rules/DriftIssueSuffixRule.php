<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class DriftIssueSuffixRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.drift_issue_suffix';
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
                foreach ($this->orphanedCodes($context->read($file)) as $code) {
                    $replacement = preg_replace('/_orphaned$/', '_extra', $code) ?? $code;

                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
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
