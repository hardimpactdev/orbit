<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class ConvertedFamilyStructureRule implements CommandDocsLintRule
{
    /**
     * @var array<string, string>
     */
    private const array STATE_FAMILY_DOCTORS = [
        'app' => 'app-doctor.md',
        'firewall-rule' => 'firewall-rule-doctor.md',
        'node' => 'node-doctor.md',
        'process' => 'process-doctor.md',
        'proxy-route' => 'proxy-route-doctor.md',
        'schedule' => 'schedule-doctor.md',
        'tool' => 'tool-doctor.md',
        'workspace' => 'workspace-doctor.md',
    ];

    public function id(): string
    {
        return 'command_docs.converted_family_structure';
    }

    public function group(): string
    {
        return 'structure';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            if (! is_file("{$familyDirectory}/README.md")) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($familyDirectory),
                    ruleId: $this->id(),
                    message: 'Converted family directories must contain README.md.',
                );
            }

            $familyName = preg_replace('/^[1-9]\d*_/', '', basename($familyDirectory));
            $doctorFile = self::STATE_FAMILY_DOCTORS[$familyName] ?? null;

            if ($doctorFile !== null && ! is_file("{$familyDirectory}/{$doctorFile}")) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($familyDirectory),
                    ruleId: $this->id(),
                    message: "State-family documentation directories must contain {$doctorFile}.",
                );
            }
        }

        return $findings;
    }
}
