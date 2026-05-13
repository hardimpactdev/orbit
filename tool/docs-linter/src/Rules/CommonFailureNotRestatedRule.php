<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class CommonFailureNotRestatedRule implements CommandDocsLintRule
{
    /**
     * Table-row failure labels that duplicate the master Common Failures table.
     *
     * @var list<string>
     */
    private const array BANNED_TABLE_LABELS = [
        'Validation failed',
        'Gateway unavailable',
        'Authorization failed',
        'Caller role not allowed',
    ];

    /**
     * Bullet labels that duplicate the master Common Failures table.
     *
     * @var list<string>
     */
    private const array BANNED_BULLET_LABELS = [
        'Validation Failures',
        'Validation Failure',
        'Validation Failed',
        'Gateway Unavailable',
        'Authorization Failed',
        'Authorization Failure',
        'Caller Role Not Allowed',
    ];

    public function id(): string
    {
        return 'command_docs.common_failure_not_restated';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory));
                $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($canonicalFile)) {
                    continue;
                }

                $contents = $context->read($canonicalFile);
                $section = $this->failureSemanticsSection($contents);

                if ($section === '') {
                    continue;
                }

                foreach (self::BANNED_TABLE_LABELS as $label) {
                    if (preg_match('/^\|\s*'.preg_quote($label, '/').'\s*\|/m', $section) === 1) {
                        $findings[] = $this->finding(
                            $context,
                            $canonicalFile,
                            "Failure Semantics restates the canonical Common Failures row '{$label}'. Remove it; document only command-specific failures.",
                        );
                    }
                }

                foreach (self::BANNED_BULLET_LABELS as $label) {
                    if (preg_match('/^-\s+\*\*'.preg_quote($label, '/').'\*\*\s*[:.]/m', $section) === 1) {
                        $findings[] = $this->finding(
                            $context,
                            $canonicalFile,
                            "Failure Semantics restates the canonical Common Failures bullet '{$label}'. Remove it; document only command-specific failures.",
                        );
                    }
                }
            }
        }

        return $findings;
    }

    private function failureSemanticsSection(string $contents): string
    {
        if (preg_match('/^## Failure Semantics\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches) === 1) {
            return $matches['section'];
        }

        return '';
    }

    private function finding(CommandDocsLintContext $context, string $file, string $message): CommandDocsLintFinding
    {
        return new CommandDocsLintFinding(
            path: $context->relativePath($file),
            ruleId: $this->id(),
            message: $message,
        );
    }
}
