<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class AppPhpVersionContractRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.app_php_version_contract';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            if (basename($familyDirectory) !== '4_app') {
                continue;
            }

            foreach ($context->markdownFiles($familyDirectory) as $file) {
                foreach ($this->violations($context->read($file)) as $message) {
                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
                        message: $message,
                    );
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
            $violations[] = 'App command docs must use `--php-version`; `--php` is not part of the converted contract.';
        }

        foreach (explode("\n", $contents) as $line) {
            if (! str_contains(strtolower($line), 'php')) {
                continue;
            }

            if (preg_match('/\bnode default\b/i', $line) !== 1) {
                continue;
            }

            $violations[] = 'App PHP version defaults must not be described as the node default; app PHP-FPM intent is separate from node CLI PHP defaults.';

            break;
        }

        return $violations;
    }
}
