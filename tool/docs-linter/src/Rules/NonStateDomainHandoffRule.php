<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class NonStateDomainHandoffRule implements CommandDocsLintRule
{
    /**
     * @var array<string, true>
     */
    private const array STATE_FAMILY_DIRECTORIES = [
        'app' => true,
        'firewall' => true,
        'node' => true,
        'process' => true,
        'proxy' => true,
        'schedule' => true,
        'tool' => true,
        'workspace' => true,
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array REQUIRED_DOCTOR_HANDOFFS = [
        'deploy' => ['app'],
        'dns' => ['node'],
        'gateway' => ['node'],
        'operation' => [
            'node',
            'app',
            'workspace',
            'process',
            'proxy_route',
            'schedule',
            'tool',
            'firewall_rule',
        ],
    ];

    public function id(): string
    {
        return 'command_docs.non_state_domain_handoff';
    }

    public function group(): string
    {
        return 'references';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            $familyName = $this->familyName($familyDirectory);

            if (isset(self::STATE_FAMILY_DIRECTORIES[$familyName])) {
                continue;
            }

            if (! isset(self::REQUIRED_DOCTOR_HANDOFFS[$familyName])) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($familyDirectory),
                    ruleId: $this->id(),
                    message: "Non-state command domain `{$familyName}` must be registered with explicit state-family doctor handoffs.",
                );

                continue;
            }

            $readme = "{$familyDirectory}/README.md";

            if (! is_file($readme)) {
                continue;
            }

            $section = $this->stateOwnershipSection($context->read($readme));

            if ($section === null) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($readme),
                    ruleId: $this->id(),
                    message: 'Non-state command domain README files must include a `## State Ownership` section.',
                );

                continue;
            }

            if (! str_contains(strtolower($section), 'does not own a state family')) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($readme),
                    ruleId: $this->id(),
                    message: '`## State Ownership` must state that the command domain does not own a state family.',
                );
            }

            foreach (self::REQUIRED_DOCTOR_HANDOFFS[$familyName] as $stateFamily) {
                if ($this->mentionsDoctorHandoff($section, $stateFamily)) {
                    continue;
                }

                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($readme),
                    ruleId: $this->id(),
                    message: "`## State Ownership` must reference `doctor --family={$stateFamily}`.",
                );
            }
        }

        return $findings;
    }

    private function familyName(string $familyDirectory): string
    {
        return preg_replace('/^[1-9]\d*_/', '', basename($familyDirectory)) ?? basename($familyDirectory);
    }

    private function stateOwnershipSection(string $contents): ?string
    {
        if (preg_match('/^## State Ownership\s*$(?<section>.*?)(?=^## |\z)/ms', $contents, $matches) !== 1) {
            return null;
        }

        return $matches['section'];
    }

    private function mentionsDoctorHandoff(string $section, string $stateFamily): bool
    {
        return preg_match('/\bdoctor\s+--family='.preg_quote($stateFamily, '/').'\b/', $section) === 1;
    }
}
