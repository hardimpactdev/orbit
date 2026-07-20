<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class AppPhpVersionContractRule implements GroupedRule
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
            if (basename($familyDirectory) !== '5_project') {
                continue;
            }

            foreach ($this->docs->markdownFiles($familyDirectory) as $file) {
                foreach ($this->violations(file_get_contents($file) ?: '') as $message) {
                    $findings[] = $this->finding($file, $message);
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function violations(string $contents): array
    {
        $violations = [];

        if (preg_match('/(?<![a-z0-9-])--php(?!-version)(?![a-z0-9-])/i', $contents) === 1) {
            $violations[] = 'Project and instance command docs must use `--php-version`; `--php` is not part of the converted contract.';
        }

        foreach (explode("\n", $contents) as $line) {
            if (! str_contains(strtolower($line), 'php')) {
                continue;
            }

            if (preg_match('/\bnode default\b/i', $line) !== 1) {
                continue;
            }

            $violations[] = 'Project PHP version defaults must not be described as the node default; project PHP-FPM intent is separate from node CLI PHP defaults.';

            break;
        }

        return $violations;
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.app_php_version_contract',
            message: $message,
        );
    }
}
