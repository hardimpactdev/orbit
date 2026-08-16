<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class DoctorIssueCodePrefixRule implements GroupedRule
{
    /**
     * @var array<string, string>
     */
    private const array FAMILY_KEYS = [
        'app' => 'instance',
        'database' => 'database_connection',
        'firewall' => 'firewall_rule',
        'node' => 'node',
        'process' => 'process',
        'proxy' => 'proxy',
        'schedule' => 'schedule',
        'tool' => 'tool',
        'workspace' => 'workspace',
    ];

    /**
     * @var array<string, string>
     */
    private const array STATE_FAMILY_DOCTORS = [
        'app' => 'instance-doctor.md',
        'database' => 'database-doctor.md',
        'firewall' => 'firewall-doctor.md',
        'node' => 'node-doctor.md',
        'process' => 'process-doctor.md',
        'proxy' => 'proxy-doctor.md',
        'schedule' => 'schedule-doctor.md',
        'tool' => 'tool-doctor.md',
        'workspace' => 'workspace-doctor.md',
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
            $familyName = $this->docs->familyName($familyDirectory);
            $familyKey = self::FAMILY_KEYS[$familyName] ?? null;
            $doctorFileName = self::STATE_FAMILY_DOCTORS[$familyName] ?? null;

            if ($familyKey === null || $doctorFileName === null) {
                continue;
            }

            $file = "{$familyDirectory}/{$doctorFileName}";

            if (! $this->docs->isFile($file)) {
                continue;
            }

            foreach ($this->doctorMapSections($this->docs->contents($file)) as $sectionName => $section) {
                foreach ($this->firstColumnCodes($section) as $code) {
                    if (str_starts_with($code, "{$familyKey}.")) {
                        continue;
                    }

                    $findings[] = new Finding(
                        path: $this->docs->relativePath($file),
                        line: null,
                        severity: FindingSeverity::Error,
                        rule: 'command_docs.doctor_issue_code_prefix',
                        message: "Doctor {$sectionName} code `{$code}` must use singular product prefix {$familyKey}.",
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function doctorMapSections(string $contents): array
    {
        $sections = [];

        foreach (['Issue Codes', 'Fix Map', 'Adopt Map'] as $suffix) {
            foreach ($this->sections($contents) as $heading => $section) {
                if (! str_ends_with($heading, $suffix)) {
                    continue;
                }

                $sections[$heading] = $section;
            }
        }

        return $sections;
    }

    /**
     * @return array<string, string>
     */
    private function sections(string $contents): array
    {
        preg_match_all('/^## (?<heading>[^\n]+)\s*$(?<section>.*?)(?=^## |\z)/ms', $contents, $matches, PREG_SET_ORDER);

        $sections = [];

        foreach ($matches as $match) {
            $sections[$match['heading']] = $match['section'];
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    private function firstColumnCodes(string $section): array
    {
        $codes = [];

        foreach (explode("\n", $section) as $line) {
            if (preg_match('/^\|\s*`(?<code>[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)`\s*\|/', $line, $matches) !== 1) {
                continue;
            }

            $codes[] = $matches['code'];
        }

        return $codes;
    }
}
