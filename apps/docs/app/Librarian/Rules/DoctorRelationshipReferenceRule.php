<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class DoctorRelationshipReferenceRule implements GroupedRule
{
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
            $familyName = $this->docs->familyName($familyDirectory);
            $doctorFileName = self::STATE_FAMILY_DOCTORS[$familyName] ?? null;

            if ($doctorFileName === null) {
                continue;
            }

            array_push($findings, ...$this->checkFamilyDoctorFile("{$familyDirectory}/{$doctorFileName}"));

            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                $commandName = $this->docs->commandName($commandDirectory);
                $canonicalFile = "{$commandDirectory}/technical/1_{$commandName}.md";

                if (! $this->docs->isFile($canonicalFile)) {
                    continue;
                }

                array_push($findings, ...$this->checkCanonicalDoctorRelationship($canonicalFile, $doctorFileName));
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkFamilyDoctorFile(string $file): array
    {
        if (! $this->docs->isFile($file)) {
            return [];
        }

        $findings = [];
        $contents = $this->docs->contents($file);

        foreach ($this->requiredDoctorSections() as $pattern => $message) {
            if (preg_match($pattern, $contents) === 1) {
                continue;
            }

            $findings[] = $this->finding($file, $message);
        }

        foreach (self::GENERIC_DOCTOR_PHRASES as $phrase) {
            if (! str_contains(strtolower($contents), $phrase)) {
                continue;
            }

            $findings[] = $this->finding(
                $file,
                "Family doctor files must not redefine generic doctor ownership language: {$phrase}.",
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkCanonicalDoctorRelationship(string $file, string $doctorFileName): array
    {
        $section = $this->section($this->docs->contents($file), 'Doctor Relationship');

        if ($section === '') {
            return [
                $this->finding($file, 'Canonical command contracts must include "## Doctor Relationship".'),
            ];
        }

        $findings = [];

        if (! str_contains($section, $doctorFileName)) {
            $findings[] = $this->finding(
                $file,
                "Doctor Relationship must reference the family doctor contract {$doctorFileName}.",
            );
        }

        foreach (self::GENERIC_DOCTOR_PHRASES as $phrase) {
            if (! str_contains(strtolower($section), $phrase)) {
                continue;
            }

            $findings[] = $this->finding(
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
        if (
            preg_match('/^## '.preg_quote($heading, '/').'\s*$(?<section>.*?)(?:^## |\z)/ms', $contents, $matches) === 1
        ) {
            return $matches['section'];
        }

        return '';
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.doctor_relationship_reference',
            message: $message,
        );
    }
}
