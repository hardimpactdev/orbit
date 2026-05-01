<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class CanonicalTechnicalContractRule implements CommandDocsLintRule
{
    /**
     * @var array<string, string>
     */
    private const array REQUIRED_SECTIONS = [
        '**Owner:**' => 'Canonical technical contracts must define "**Owner:**".',
        '**Effects:**' => 'Canonical technical contracts must define "**Effects:**".',
        '**Prerequisites:**' => 'Canonical technical contracts must define "**Prerequisites:**".',
        "\n## Signature" => 'Canonical technical contracts must include "## Signature".',
        "\n## Input Contract" => 'Canonical technical contracts must include "## Input Contract".',
        "\n## Behavior Contract" => 'Canonical technical contracts must include "## Behavior Contract".',
        "\n## Failure Semantics" => 'Canonical technical contracts must include "## Failure Semantics".',
        "\n## Doctor Relationship" => 'Canonical technical contracts must include "## Doctor Relationship".',
        "\n## Test Mapping" => 'Canonical technical contracts must include "## Test Mapping".',
    ];

    public function id(): string
    {
        return 'command_docs.canonical_technical_contract';
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
                $file = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($file)) {
                    continue;
                }

                $contents = $context->read($file);

                foreach (self::REQUIRED_SECTIONS as $needle => $message) {
                    if (! str_contains($contents, $needle)) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($file),
                            ruleId: $this->id(),
                            message: $message,
                        );
                    }
                }
            }
        }

        return $findings;
    }
}
