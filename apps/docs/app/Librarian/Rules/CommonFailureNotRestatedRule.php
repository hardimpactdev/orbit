<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class CommonFailureNotRestatedRule implements GroupedRule
{
    /**
     * @var list<string>
     */
    private const array BANNED_TABLE_LABELS = [
        'Validation failed',
        'Gateway unavailable',
        'Authorization failed',
        'Caller role not allowed',
    ];

    /**
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
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = $this->docs->commandName($commandDirectory);
                $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! $this->docs->isFile($canonicalFile)) {
                    continue;
                }

                $section = $this->failureSemanticsSection($this->docs->contents($canonicalFile));

                if ($section === '') {
                    continue;
                }

                foreach (self::BANNED_TABLE_LABELS as $label) {
                    if (preg_match('/^\|\s*'.preg_quote($label, '/').'\s*\|/m', $section) !== 1) {
                        continue;
                    }

                    $findings[] = $this->finding(
                        $canonicalFile,
                        "Failure Semantics restates the canonical Common Failures row '{$label}'. Remove it; document only command-specific failures.",
                    );
                }

                foreach (self::BANNED_BULLET_LABELS as $label) {
                    if (preg_match('/^-\s+\*\*'.preg_quote($label, '/').'\*\*\s*[:.]/m', $section) !== 1) {
                        continue;
                    }

                    $findings[] = $this->finding(
                        $canonicalFile,
                        "Failure Semantics restates the canonical Common Failures bullet '{$label}'. Remove it; document only command-specific failures.",
                    );
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

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.common_failure_not_restated',
            message: $message,
        );
    }
}
