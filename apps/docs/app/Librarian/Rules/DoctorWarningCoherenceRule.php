<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\CommandDocsRegistry;
use App\Librarian\DoctorIssueTableParser;
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
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $findings = [];
        $stateFamilies = $this->registry->stateFamilies();
        $warningCodes = $this->registry->warningCodes();
        $doctorIssues = [];

        foreach ($this->jsonRendererFiles() as $file) {
            foreach ($this->jsonParser->parse($file, file_get_contents($file) ?: '') as $example) {
                if (! $example->isValidArray() || ! is_array($example->decoded)) {
                    continue;
                }

                foreach ($this->warnings($example->decoded) as $warning) {
                    if (! is_array($warning)) {
                        continue;
                    }

                    $code = $warning['code'] ?? null;
                    $family = $warning['family'] ?? null;
                    $nextCommand = $warning['next_command'] ?? null;

                    if (! is_string($code)) {
                        continue;
                    }

                    if ($family === null) {
                        if (is_string($nextCommand) && $this->nextCommandFamily($nextCommand) !== null) {
                            $findings[] = $this->finding(
                                $file,
                                $example->line,
                                'Command-owned warnings with null family must not point at a doctor next_command.',
                            );
                        }

                        continue;
                    }

                    if (! is_string($family) || ! isset($stateFamilies[$family])) {
                        $findings[] = $this->finding(
                            $file,
                            $example->line,
                            "Warning code `{$code}` uses unknown doctor family `"
                            .(is_scalar($family) ? (string) $family : gettype($family))
                            .'`.',
                        );

                        continue;
                    }

                    $singular = $stateFamilies[$family]['singular'];

                    if (! str_starts_with($code, "{$singular}.")) {
                        $findings[] = $this->finding(
                            $file,
                            $example->line,
                            "Warning code `{$code}` with family `{$family}` must use singular product prefix `{$singular}.`.",
                        );
                    }

                    if (! is_string($nextCommand) || $nextCommand === '') {
                        continue;
                    }

                    $nextCommandFamily = $this->nextCommandFamily($nextCommand);

                    if ($nextCommandFamily !== null && $nextCommandFamily !== $family) {
                        $findings[] = $this->finding(
                            $file,
                            $example->line,
                            "Warning code `{$code}` uses family `{$family}` but next_command points at `{$nextCommandFamily}`.",
                        );
                    }

                    $doctorPath = $stateFamilies[$family]['doctor_doc'];

                    if ($doctorPath === null) {
                        continue;
                    }

                    $absoluteDoctorPath = "{$this->docs->docsRoot()}/{$doctorPath}";

                    if (! is_file($absoluteDoctorPath)) {
                        continue;
                    }

                    $doctorIssues[$family] ??= $this->doctorParser->parse(file_get_contents($absoluteDoctorPath) ?: '');
                    $issue = $doctorIssues[$family][$code] ?? null;

                    if ($issue === null) {
                        if ($this->isRegisteredCommandHandoff($warningCodes, $code, $family, $nextCommand)) {
                            continue;
                        }

                        $findings[] = $this->finding(
                            path: $file,
                            line: $example->line,
                            message: "Warning code `{$code}` is not listed in the {$family} doctor issue codes. If this is a command-level handoff warning, document it as such; otherwise use a doctor issue code.",
                            severity: FindingSeverity::Warning,
                        );

                        continue;
                    }

                    if (str_contains($nextCommand, '--fix') && ! $issue->hasFix) {
                        $findings[] = $this->finding(
                            $file,
                            $example->line,
                            "Warning code `{$code}` points at --fix but is not listed in the {$family} doctor fix map.",
                        );
                    }

                    if (str_contains($nextCommand, '--adopt') && ! $issue->hasAdopt) {
                        $findings[] = $this->finding(
                            $file,
                            $example->line,
                            "Warning code `{$code}` points at --adopt but is not listed in the {$family} doctor adopt map.",
                        );
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, array{family?: ?string, kind?: string, allowed_next_commands?: list<string>}>  $warningCodes
     */
    private function isRegisteredCommandHandoff(
        array $warningCodes,
        string $code,
        string $family,
        string $nextCommand,
    ): bool {
        $warning = $warningCodes[$code] ?? null;

        if ($warning === null) {
            return false;
        }

        if (($warning['kind'] ?? null) !== 'command_handoff') {
            return false;
        }

        if (($warning['family'] ?? null) !== $family) {
            return false;
        }

        foreach ($warning['allowed_next_commands'] ?? [] as $allowedNextCommand) {
            if ($nextCommand === $allowedNextCommand || str_starts_with($nextCommand, "{$allowedNextCommand} ")) {
                return true;
            }
        }

        return false;
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
