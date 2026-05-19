<?php

declare(strict_types=1);

namespace App\Docs\Librarian\Rules;

use App\Docs\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class NonStateDomainHandoffRule implements GroupedRule
{
    /**
     * @var array<string, true>
     */
    private const array STATE_FAMILY_DIRECTORIES = [
        'app' => true,
        'database' => true,
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
        'activity' => ['node', 'app', 'workspace', 'process', 'proxy', 'schedule', 'tool', 'firewall_rule'],
        'agent-ide' => ['node', 'app', 'workspace', 'process', 'tool'],
        'cf' => ['proxy', 'app'],
        'deploy' => ['app'],
        'dns' => ['node'],
        'gateway' => ['node'],
        'php' => ['tool', 'app', 'workspace', 'proxy', 'node'],
        'operation' => ['node', 'app', 'workspace', 'process', 'proxy', 'schedule', 'tool', 'firewall_rule'],
        'vpn' => ['node'],
    ];

    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'references';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            array_push($findings, ...$this->checkFamilyDirectory($familyDirectory));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkFamilyDirectory(string $familyDirectory): array
    {
        $familyName = $this->docs->familyName($familyDirectory);

        if (isset(self::STATE_FAMILY_DIRECTORIES[$familyName])) {
            return [];
        }

        if (! isset(self::REQUIRED_DOCTOR_HANDOFFS[$familyName])) {
            return [
                $this->finding(
                    $familyDirectory,
                    "Non-state command domain `{$familyName}` must be registered with explicit state-family doctor handoffs.",
                ),
            ];
        }

        $readme = "{$familyDirectory}/README.md";

        if (! is_file($readme)) {
            return [];
        }

        $section = $this->stateOwnershipSection(file_get_contents($readme) ?: '');

        if ($section === null) {
            return [
                $this->finding($readme, 'Non-state command domain README files must include a `## State Ownership` section.'),
            ];
        }

        $findings = [];

        if (! str_contains(strtolower($section), 'does not own a state family')) {
            $findings[] = $this->finding($readme, '`## State Ownership` must state that the command domain does not own a state family.');
        }

        foreach (self::REQUIRED_DOCTOR_HANDOFFS[$familyName] as $stateFamily) {
            if ($this->mentionsDoctorHandoff($section, $stateFamily)) {
                continue;
            }

            $findings[] = $this->finding($readme, "`## State Ownership` must reference `doctor --family={$stateFamily}`.");
        }

        return $findings;
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

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.non_state_domain_handoff',
            message: $message,
        );
    }
}
