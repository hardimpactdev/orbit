<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class DoctorIssueCodePrefixRule implements CommandDocsLintRule
{
    /**
     * @var array<string, string>
     */
    private const array FAMILY_KEYS = [
        'app' => 'app',
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
        'app' => 'app-doctor.md',
        'firewall' => 'firewall-doctor.md',
        'node' => 'node-doctor.md',
        'process' => 'process-doctor.md',
        'proxy' => 'proxy-doctor.md',
        'schedule' => 'schedule-doctor.md',
        'tool' => 'tool-doctor.md',
        'workspace' => 'workspace-doctor.md',
    ];

    public function id(): string
    {
        return 'command_docs.doctor_issue_code_prefix';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            $familyName = preg_replace('/^[1-9]\d*_/', '', basename($familyDirectory));
            $familyKey = self::FAMILY_KEYS[$familyName] ?? null;
            $doctorFileName = self::STATE_FAMILY_DOCTORS[$familyName] ?? null;

            if ($familyKey === null || $doctorFileName === null) {
                continue;
            }

            $file = "{$familyDirectory}/{$doctorFileName}";

            if (! is_file($file)) {
                continue;
            }

            foreach ($this->doctorMapSections($context->read($file)) as $sectionName => $section) {
                foreach ($this->firstColumnCodes($section) as $code) {
                    if (str_starts_with($code, "{$familyKey}.")) {
                        continue;
                    }

                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
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
            if (preg_match('/^## (?<heading>.*'.preg_quote($suffix, '/').')\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches) === 1) {
                $sections[$matches['heading']] = $matches['section'];
            }
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
