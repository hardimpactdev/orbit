<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;
use OrbitDocsLinter\CommandDocsRegistry;
use OrbitDocsLinter\DoctorIssueTableParser;
use OrbitDocsLinter\JsonExampleParser;

final class DoctorWarningCoherenceRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.doctor_warning_coherence';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];
        $jsonParser = new JsonExampleParser;
        $doctorParser = new DoctorIssueTableParser;
        $registry = new CommandDocsRegistry($context->repositoryRoot);
        $stateFamilies = $registry->stateFamilies();
        $warningCodes = $registry->warningCodes();
        $doctorIssues = [];

        foreach ($this->jsonRendererFiles($context) as $file) {
            foreach ($jsonParser->parse($file, $context->read($file)) as $example) {
                if (! $example->isValidArray()) {
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
                            $findings[] = $this->finding($context, $file, $example->line, 'Command-owned warnings with null family must not point at a doctor next_command.');
                        }

                        continue;
                    }

                    if (! is_string($family) || ! isset($stateFamilies[$family])) {
                        $findings[] = $this->finding($context, $file, $example->line, "Warning code `{$code}` uses unknown doctor family `".(is_scalar($family) ? (string) $family : gettype($family)).'`.');

                        continue;
                    }

                    $singular = $stateFamilies[$family]['singular'];

                    if (! str_starts_with($code, "{$singular}.")) {
                        $findings[] = $this->finding($context, $file, $example->line, "Warning code `{$code}` with family `{$family}` must use singular product prefix `{$singular}.`.");
                    }

                    if (! is_string($nextCommand) || $nextCommand === '') {
                        continue;
                    }

                    $nextCommandFamily = $this->nextCommandFamily($nextCommand);

                    if ($nextCommandFamily !== null && $nextCommandFamily !== $family) {
                        $findings[] = $this->finding($context, $file, $example->line, "Warning code `{$code}` uses family `{$family}` but next_command points at `{$nextCommandFamily}`.");
                    }

                    $doctorPath = $stateFamilies[$family]['doctor_doc'];

                    if ($doctorPath === null) {
                        continue;
                    }

                    $absoluteDoctorPath = "{$context->repositoryRoot}/{$doctorPath}";

                    if (! is_file($absoluteDoctorPath)) {
                        continue;
                    }

                    $doctorIssues[$family] ??= $doctorParser->parse($context->read($absoluteDoctorPath));
                    $issue = $doctorIssues[$family][$code] ?? null;

                    if ($issue === null) {
                        if ($this->isRegisteredCommandHandoff($warningCodes, $code, $family, $nextCommand)) {
                            continue;
                        }

                        $findings[] = $this->finding(
                            context: $context,
                            path: $file,
                            line: $example->line,
                            message: "Warning code `{$code}` is not listed in the {$family} doctor issue codes. If this is a command-level handoff warning, document it as such; otherwise use a doctor issue code.",
                            severity: CommandDocsLintSeverity::Warning,
                        );

                        continue;
                    }

                    if (str_contains($nextCommand, '--fix') && ! $issue->hasFix) {
                        $findings[] = $this->finding($context, $file, $example->line, "Warning code `{$code}` points at --fix but is not listed in the {$family} doctor fix map.");
                    }

                    if (str_contains($nextCommand, '--adopt') && ! $issue->hasAdopt) {
                        $findings[] = $this->finding($context, $file, $example->line, "Warning code `{$code}` points at --adopt but is not listed in the {$family} doctor adopt map.");
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, array{family?: ?string, kind?: string, allowed_next_commands?: list<string>}>  $warningCodes
     */
    private function isRegisteredCommandHandoff(array $warningCodes, string $code, string $family, string $nextCommand): bool
    {
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
    private function jsonRendererFiles(CommandDocsLintContext $context): array
    {
        $files = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                foreach ($context->technicalMarkdownFiles("{$commandDirectory}/technical") as $file) {
                    if (preg_match('/^6\.2_.*_output-render_json\.md$/', basename($file)) === 1) {
                        $files[] = $file;
                    }
                }
            }
        }

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
        CommandDocsLintContext $context,
        string $path,
        int $line,
        string $message,
        CommandDocsLintSeverity $severity = CommandDocsLintSeverity::Error,
    ): CommandDocsLintFinding {
        return new CommandDocsLintFinding(
            path: $context->relativePath($path),
            ruleId: $this->id(),
            message: $message,
            severity: $severity,
            line: $line,
        );
    }
}
