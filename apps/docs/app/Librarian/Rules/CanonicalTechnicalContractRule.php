<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class CanonicalTechnicalContractRule implements GroupedRule
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
                $file = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! $this->docs->isFile($file)) {
                    continue;
                }

                $contents = $this->docs->contents($file);

                foreach (self::REQUIRED_SECTIONS as $needle => $message) {
                    if (str_contains($contents, $needle)) {
                        continue;
                    }

                    $findings[] = $this->finding($file, $message);
                }
            }
        }

        return $findings;
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.canonical_technical_contract',
            message: $message,
        );
    }
}
