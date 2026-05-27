<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class TechnicalSlotSemanticsRule implements GroupedRule
{
    /**
     * @var array<string, string>
     */
    private const array RESERVED_SLOT_SUFFIXES = [
        'slot:1' => '',
        'slot:2' => '_on-client',
        'slot:3' => '_on-gateway-node',
        'slot:4' => '_on-app-role',
        'slot:5.1' => '_input-mode_interactive',
        'slot:5.2' => '_input-mode_non-interactive',
        'slot:6.1' => '_output-render_human',
        'slot:6.2' => '_output-render_json',
    ];

    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'structure';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                array_push($findings, ...$this->checkCommandDirectory($commandDirectory));
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkCommandDirectory(string $commandDirectory): array
    {
        $findings = [];
        $commandName = $this->docs->commandName($commandDirectory);

        foreach ($this->docs->markdownFiles("{$commandDirectory}/technical", recursive: false) as $file) {
            $filename = basename($file);

            if (preg_match('/^(?<slot>[1-9]\d*(?:\.[1-9]\d*)?)_/', $filename, $matches) !== 1) {
                continue;
            }

            $slot = (string) $matches['slot'];
            $suffix = self::RESERVED_SLOT_SUFFIXES["slot:{$slot}"] ?? null;

            if ($suffix === null) {
                continue;
            }

            $expected = "{$slot}_{$commandName}{$suffix}.md";

            if ($filename === $expected) {
                continue;
            }

            $findings[] = new Finding(
                path: $this->docs->relativePath($file),
                line: null,
                severity: FindingSeverity::Error,
                rule: 'command_docs.technical_slot_semantics',
                message: "Reserved technical slot {$slot} must be named {$expected}.",
            );
        }

        return $findings;
    }
}
