<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\CommandDocsRegistry;
use App\Librarian\DoctorIssueTableParser;
use App\Librarian\DoctorWarningInspection;
use App\Librarian\DoctorWarningTableParser;
use App\Librarian\JsonExampleParser;
use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class DoctorWarningCoherenceRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
        private JsonExampleParser $jsonParser,
        private DoctorIssueTableParser $doctorParser,
        private CommandDocsRegistry $registry,
        private DoctorWarningTableParser $warningTableParser,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $findings = [];
        $inspection = new DoctorWarningInspection(
            stateFamilies: $this->registry->stateFamilies(),
            warningCodes: $this->registry->warningCodes(),
        );

        foreach ($this->jsonRendererFiles() as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($this->jsonParser->parse($file, $contents) as $example) {
                if (! $example->isValidArray() || ! is_array($example->decoded)) {
                    continue;
                }

                foreach ($this->warnings($example->decoded) as $warning) {
                    if (! is_array($warning)) {
                        continue;
                    }

                    array_push(
                        $findings,
                        ...$this->checkWarning(
                            file: $file,
                            line: $example->line,
                            warning: $warning,
                            inspection: $inspection,
                        ),
                    );
                }
            }

            $tableWarnings = $this->warningTableParser->parse($contents);
            $firstTableLineByCode = [];

            foreach ($tableWarnings as $warning) {
                $code = $warning['code'];

                if (array_key_exists($code, $firstTableLineByCode)) {
                    $findings[] = $this->finding(
                        path: $file,
                        line: $warning['line'],
                        message: "Warning code `{$code}` is duplicated in this warning-code table; the first declaration is on line {$firstTableLineByCode[$code]}.",
                    );
                } else {
                    $firstTableLineByCode[$code] = $warning['line'];
                }

                array_push(
                    $findings,
                    ...$this->checkWarning(
                        file: $file,
                        line: $warning['line'],
                        warning: $warning,
                        inspection: $inspection,
                    ),
                );
            }
        }

        return $findings;
    }

    /**
     * @param  array<mixed>  $warning
     * @return list<Finding>
     */
    private function checkWarning(
        string $file,
        int $line,
        array $warning,
        DoctorWarningInspection $inspection,
    ): array {
        $code = $warning['code'] ?? null;
        $family = $warning['family'] ?? null;
        $nextCommand = $warning['next_command'] ?? null;

        if (! is_string($code)) {
            return [];
        }

        if ($family === null) {
            if (is_string($nextCommand) && $this->nextCommandFamily($nextCommand) !== null) {
                return [$this->finding(
                    $file,
                    $line,
                    'Command-owned warnings with null family must not point at a doctor next_command.',
                )];
            }

            if (
                is_string($nextCommand)
                && $nextCommand !== ''
                && ! $this->isRegisteredCommandHandoff($inspection, $code, null, $nextCommand)
            ) {
                return [$this->finding(
                    path: $file,
                    line: $line,
                    message: "Warning code `{$code}` uses a null-family command handoff that is not registered for `{$nextCommand}`.",
                    severity: FindingSeverity::Warning,
                )];
            }

            return [];
        }

        if (! is_string($family) || ! array_key_exists($family, $inspection->stateFamilies)) {
            return [$this->finding(
                $file,
                $line,
                "Warning code `{$code}` uses unknown doctor family `"
                .(is_scalar($family) ? (string) $family : gettype($family))
                .'`.',
            )];
        }

        $findings = [];
        $singular = $inspection->stateFamilies[$family]['singular'];

        if (! str_starts_with($code, "{$singular}.")) {
            $findings[] = $this->finding(
                $file,
                $line,
                "Warning code `{$code}` with family `{$family}` must use singular product prefix `{$singular}.`.",
            );
        }

        if (! is_string($nextCommand) || $nextCommand === '') {
            return $findings;
        }

        $nextCommandFamily = $this->nextCommandFamily($nextCommand);

        if ($nextCommandFamily !== null && $nextCommandFamily !== $family) {
            $findings[] = $this->finding(
                $file,
                $line,
                "Warning code `{$code}` uses family `{$family}` but next_command points at `{$nextCommandFamily}`.",
            );
        }

        $doctorPath = $inspection->stateFamilies[$family]['doctor_doc'];

        if ($doctorPath === null) {
            return $findings;
        }

        $absoluteDoctorPath = "{$this->docs->docsRoot()}/{$doctorPath}";

        if (! is_file($absoluteDoctorPath)) {
            return $findings;
        }

        $inspection->doctorIssues[$family] ??= $this->doctorParser->parse(
            (string) file_get_contents($absoluteDoctorPath),
        );
        $issue = $inspection->doctorIssues[$family][$code] ?? null;

        if ($issue === null) {
            if ($this->isRegisteredCommandHandoff($inspection, $code, $family, $nextCommand)) {
                return $findings;
            }

            $findings[] = $this->finding(
                path: $file,
                line: $line,
                message: "Warning code `{$code}` is not listed in the {$family} doctor issue codes. If this is a command-level handoff warning, document it as such; otherwise use a doctor issue code.",
                severity: FindingSeverity::Warning,
            );

            return $findings;
        }

        if (str_contains($nextCommand, '--fix') && ! $issue->hasFix) {
            $findings[] = $this->finding(
                $file,
                $line,
                "Warning code `{$code}` points at --fix but is not listed in the {$family} doctor fix map.",
            );
        }

        if (str_contains($nextCommand, '--adopt') && ! $issue->hasAdopt) {
            $findings[] = $this->finding(
                $file,
                $line,
                "Warning code `{$code}` points at --adopt but is not listed in the {$family} doctor adopt map.",
            );
        }

        return $findings;
    }

    private function isRegisteredCommandHandoff(
        DoctorWarningInspection $inspection,
        string $code,
        ?string $family,
        string $nextCommand,
    ): bool {
        $warning = $inspection->warningCodes[$code] ?? null;

        if ($warning === null) {
            return false;
        }

        $kind = $warning['kind'] ?? null;

        if ($kind !== 'command_handoff' && ! ($family === null && $kind === 'command_owned')) {
            return false;
        }

        if (($warning['family'] ?? null) !== $family) {
            return false;
        }

        foreach ($warning['allowed_next_commands'] ?? [] as $allowedNextCommand) {
            $normalizedNextCommand = $this->normalizeCommand($nextCommand);
            $normalizedAllowedCommand = $this->normalizeCommand($allowedNextCommand);

            if (
                $normalizedNextCommand === $normalizedAllowedCommand
                || str_starts_with($normalizedNextCommand, "{$normalizedAllowedCommand} ")
            ) {
                return true;
            }
        }

        return false;
    }

    private function normalizeCommand(string $command): string
    {
        $command = trim($command);

        return str_starts_with($command, 'orbit ') ? substr($command, strlen('orbit ')) : $command;
    }

    /**
     * @return list<string>
     */
    private function jsonRendererFiles(): array
    {
        $files = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                foreach ($this->docs->markdownFiles("{$commandDirectory}/technical", recursive: false) as $file) {
                    if (preg_match('/^6\.2_.*_output-render_json\.md$/', basename($file)) === 1) {
                        $files[] = $file;
                    }
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<mixed>
     */
    private function warnings(array $decoded): array
    {
        $warnings = $decoded['success']['meta']['warnings'] ?? null;

        if (! is_array($warnings)) {
            return [];
        }

        return array_values($warnings);
    }

    private function nextCommandFamily(string $nextCommand): ?string
    {
        $matches = [];

        if (preg_match('/(?:^|\s)doctor(?::fix)?\s+.*?--family=(?<family>[a-z_]+)/', $nextCommand, $matches) !== 1) {
            return null;
        }

        return $matches['family'];
    }

    private function finding(
        string $path,
        int $line,
        string $message,
        FindingSeverity $severity = FindingSeverity::Error,
    ): Finding {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: $line,
            severity: $severity,
            rule: 'command_docs.doctor_warning_coherence',
            message: $message,
        );
    }
}
