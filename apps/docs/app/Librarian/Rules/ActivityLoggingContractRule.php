<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class ActivityLoggingContractRule implements GroupedRule
{
    /**
     * @var list<string>
     */
    private const array REQUIRED_FIELDS = [
        'Type',
        'Effect',
        'Subject',
        'Properties',
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

                $section = $this->activityLoggingSection($this->docs->contents($file));

                if ($section === null) {
                    $findings[] = $this->finding(
                        $file,
                        'Canonical technical contracts must include `## Activity Logging` declaring the per-command Loggable contract.',
                    );

                    continue;
                }

                if ($this->declaresNoEmission($section)) {
                    continue;
                }

                foreach (self::REQUIRED_FIELDS as $field) {
                    if ($this->mentionsField($section, $field)) {
                        continue;
                    }

                    $findings[] = $this->finding(
                        $file,
                        "`## Activity Logging` must include a `{$field}` row, or declare the command does not emit.",
                    );
                }
            }
        }

        return $findings;
    }

    private function activityLoggingSection(string $contents): ?string
    {
        if (preg_match('/^## Activity Logging\s*$(?<section>.*?)(?=^## |\z)/ms', $contents, $matches) !== 1) {
            return null;
        }

        return $matches['section'];
    }

    private function declaresNoEmission(string $section): bool
    {
        return str_contains(strtolower($section), 'does not emit');
    }

    private function mentionsField(string $section, string $field): bool
    {
        return preg_match('/^\|\s*'.preg_quote($field, '/').'\s*\|/m', $section) === 1;
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.activity_logging_contract',
            message: $message,
        );
    }
}
