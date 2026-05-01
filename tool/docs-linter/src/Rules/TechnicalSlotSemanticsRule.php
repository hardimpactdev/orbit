<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class TechnicalSlotSemanticsRule implements CommandDocsLintRule
{
    /**
     * @var array<string, string>
     */
    private const array RESERVED_SLOT_SUFFIXES = [
        'slot:1' => '',
        'slot:2' => '_on-control-node',
        'slot:3' => '_on-gateway-node',
        'slot:4' => '_on-app-node',
        'slot:5.1' => '_input-mode_interactive',
        'slot:5.2' => '_input-mode_non-interactive',
        'slot:6.1' => '_output-render_human',
        'slot:6.2' => '_output-render_json',
    ];

    public function id(): string
    {
        return 'command_docs.technical_slot_semantics';
    }

    public function group(): string
    {
        return 'structure';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory));

                foreach ($context->technicalMarkdownFiles("{$commandDirectory}/technical") as $file) {
                    $filename = basename($file);

                    if (preg_match('/^(?<slot>[1-9]\d*(?:\.[1-9]\d*)?)_/', $filename, $matches) !== 1) {
                        continue;
                    }

                    $slot = $matches['slot'];
                    $suffix = self::RESERVED_SLOT_SUFFIXES["slot:{$slot}"] ?? null;

                    if ($suffix === null) {
                        continue;
                    }

                    $expected = "{$slot}_{$commandName}{$suffix}.md";

                    if ($filename !== $expected) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($file),
                            ruleId: $this->id(),
                            message: "Reserved technical slot {$slot} must be named {$expected}.",
                        );
                    }
                }
            }
        }

        return $findings;
    }
}
