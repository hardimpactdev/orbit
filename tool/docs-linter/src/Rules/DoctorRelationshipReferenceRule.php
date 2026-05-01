<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class DoctorRelationshipReferenceRule implements CommandDocsLintRule
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

    /**
     * @var list<string>
     */
    private const array GENERIC_DOCTOR_PHRASES = [
        'command owns orchestration',
        'generic doctor contract',
        'generic issue kinds',
        'mode semantics',
        'orchestration, scoping',
        'output envelopes',
        'scope resolution',
        'standard doctor issue kinds',
    ];

    public function id(): string
    {
        return 'command_docs.doctor_relationship_reference';
    }

    public function group(): string
    {
        return 'references';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            $familyName = preg_replace('/^[1-9]\d*_/', '', basename($familyDirectory));
            $doctorFileName = self::STATE_FAMILY_DOCTORS[$familyName] ?? null;

            if ($doctorFileName === null) {
                continue;
            }

            $findings = [
                ...$findings,
                ...$this->checkFamilyDoctorFile($context, "{$familyDirectory}/{$doctorFileName}"),
            ];

            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = preg_replace('/^[1-9]\d*_/', '', basename($commandDirectory));
                $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! is_file($canonicalFile)) {
                    continue;
                }

                $findings = [
                    ...$findings,
                    ...$this->checkCanonicalDoctorRelationship($context, $canonicalFile, $doctorFileName),
                ];
            }
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkFamilyDoctorFile(CommandDocsLintContext $context, string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $findings = [];
        $contents = $context->read($file);

        foreach ($this->requiredDoctorSections() as $pattern => $message) {
            if (preg_match($pattern, $contents) === 1) {
                continue;
            }

            $findings[] = $this->finding($context, $file, $message);
        }

        foreach (self::GENERIC_DOCTOR_PHRASES as $phrase) {
            if (! str_contains(strtolower($contents), $phrase)) {
                continue;
            }

            $findings[] = $this->finding(
                $context,
                $file,
                "Family doctor files must not redefine generic doctor ownership language: {$phrase}.",
            );
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkCanonicalDoctorRelationship(CommandDocsLintContext $context, string $file, string $doctorFileName): array
    {
        $section = $this->section($context->read($file), 'Doctor Relationship');

        if ($section === '') {
            return [
                $this->finding($context, $file, 'Canonical command contracts must include "## Doctor Relationship".'),
            ];
        }

        $findings = [];

        if (! str_contains($section, $doctorFileName)) {
            $findings[] = $this->finding(
                $context,
                $file,
                "Doctor Relationship must reference the family doctor contract {$doctorFileName}.",
            );
        }

        foreach (self::GENERIC_DOCTOR_PHRASES as $phrase) {
            if (! str_contains(strtolower($section), $phrase)) {
                continue;
            }

            $findings[] = $this->finding(
                $context,
                $file,
                "Command Doctor Relationship sections must link to family doctor docs instead of redefining generic doctor behavior: {$phrase}.",
            );
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function requiredDoctorSections(): array
    {
        return [
            '/^## .*Probe/sm' => 'Family doctor files must define probe facts or probe layers.',
            '/^## .*Issue Codes\s*$/m' => 'Family doctor files must define concrete family issue codes.',
            '/^## .*Fix Map\s*$/m' => 'Family doctor files must define a concrete family fix map.',
            '/^## .*Adopt Map\s*$/m' => 'Family doctor files must define a concrete family adopt map.',
            '/^## Test Mapping\s*$/m' => 'Family doctor files must include "## Test Mapping".',
        ];
    }

    private function section(string $contents, string $heading): string
    {
        if (preg_match('/^## '.preg_quote($heading, '/').'\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches) === 1) {
            return $matches['section'];
        }

        return '';
    }

    private function finding(CommandDocsLintContext $context, string $path, string $message): CommandDocsLintFinding
    {
        return new CommandDocsLintFinding(
            path: $context->relativePath($path),
            ruleId: $this->id(),
            message: $message,
        );
    }
}
