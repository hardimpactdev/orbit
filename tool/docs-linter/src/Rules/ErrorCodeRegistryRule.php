<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;
use OrbitDocsLinter\CommandDocsRegistry;
use OrbitDocsLinter\JsonExampleParser;

final class ErrorCodeRegistryRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.error_code_registry';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];
        $registry = new CommandDocsRegistry($context->repositoryRoot);
        $stateFamilies = $registry->stateFamilies();
        $errorCodes = $registry->errorCodes();
        $enforcedFamilies = array_fill_keys($errorCodes['enforced_families'] ?? [], true);
        $sharedCodes = array_fill_keys($errorCodes['shared'] ?? [], true);
        $productCodes = $errorCodes['products'] ?? [];
        $jsonParser = new JsonExampleParser;

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            $family = $this->stateFamilyForDirectory($familyDirectory, $stateFamilies);

            if ($family === null || ! isset($enforcedFamilies[$family])) {
                continue;
            }

            foreach ($this->jsonRendererFiles($context, $familyDirectory) as $file) {
                $contents = $context->read($file);
                $exampleCodes = [];

                foreach ($jsonParser->parse($file, $contents) as $example) {
                    if (! $example->isValidArray()) {
                        continue;
                    }

                    $code = $example->decoded['error']['code'] ?? null;

                    if (! is_string($code)) {
                        continue;
                    }

                    $exampleCodes[$code] = $example->line;

                    $finding = $this->findingForCode(
                        context: $context,
                        path: $file,
                        line: $example->line,
                        code: $code,
                        sharedCodes: $sharedCodes,
                        productCodes: $productCodes,
                    );

                    if ($finding !== null) {
                        $findings[] = $finding;
                    }
                }

                array_push($findings, ...$this->tableFindings($context, $file, $contents, $exampleCodes));
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, int>  $exampleCodes
     * @return list<CommandDocsLintFinding>
     */
    private function tableFindings(CommandDocsLintContext $context, string $path, string $contents, array $exampleCodes): array
    {
        $table = $this->errorCodeTable($contents);

        if ($table === null) {
            return [];
        }

        $findings = [];
        $tableCodes = array_fill_keys($table['codes'], true);

        foreach ($exampleCodes as $code => $line) {
            if (isset($tableCodes[$code])) {
                continue;
            }

            $findings[] = $this->finding($context, $path, $line, "Error code `{$code}` is used by a JSON error example but is missing from the exhaustive `error.code` table.");
        }

        foreach ($table['codes'] as $code) {
            if (isset($exampleCodes[$code])) {
                continue;
            }

            $findings[] = $this->finding(
                context: $context,
                path: $path,
                line: $table['line'],
                message: "Error code `{$code}` is listed in the exhaustive `error.code` table but has no JSON error example.",
                severity: CommandDocsLintSeverity::Warning,
            );
        }

        return $findings;
    }

    /**
     * @return array{codes: list<string>, line: int}|null
     */
    private function errorCodeTable(string $contents): ?array
    {
        if (preg_match('/^\|\s*`error\.code`\s*\|\s*(?<codes>.*?)\s*\|/m', $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $codes = array_values(array_filter(array_map(
            fn (string $code): string => trim($code, " \t\n\r\0\x0B`"),
            explode(',', $matches['codes'][0]),
        )));

        return [
            'codes' => $codes,
            'line' => $this->lineForOffset($contents, $matches[0][1]),
        ];
    }

    /**
     * @param  array<string, array{singular: string, doctor_doc: ?string}>  $stateFamilies
     */
    private function stateFamilyForDirectory(string $familyDirectory, array $stateFamilies): ?string
    {
        $singular = preg_replace('/^[1-9]\d*_/', '', basename($familyDirectory));

        foreach ($stateFamilies as $family => $metadata) {
            if ($metadata['singular'] === $singular) {
                return $family;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function jsonRendererFiles(CommandDocsLintContext $context, string $familyDirectory): array
    {
        $files = [];

        foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
            foreach ($context->technicalMarkdownFiles("{$commandDirectory}/technical") as $file) {
                if (preg_match('/^6\.2_.*_output-render_json\.md$/', basename($file)) === 1) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * @param  array<string, true>  $sharedCodes
     * @param  array<string, list<string>>  $productCodes
     */
    private function findingForCode(
        CommandDocsLintContext $context,
        string $path,
        int $line,
        string $code,
        array $sharedCodes,
        array $productCodes,
    ): ?CommandDocsLintFinding {
        if (! str_contains($code, '.')) {
            if (isset($sharedCodes[$code])) {
                return null;
            }

            return $this->finding($context, $path, $line, "Error code `{$code}` is not a registered shared error code.");
        }

        [$product, $condition] = explode('.', $code, 2);

        if (! isset($productCodes[$product])) {
            return $this->finding($context, $path, $line, "Error code `{$code}` uses unregistered product prefix `{$product}`.");
        }

        if (in_array($condition, $productCodes[$product], true)) {
            return null;
        }

        return $this->finding($context, $path, $line, "Error code `{$code}` is not registered under product `{$product}`.");
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

    private function lineForOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }
}
