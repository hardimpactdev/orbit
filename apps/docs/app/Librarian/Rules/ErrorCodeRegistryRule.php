<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\CommandDocsRegistry;
use App\Librarian\JsonExampleParser;
use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class ErrorCodeRegistryRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
        private JsonExampleParser $parser,
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
        $errorCodes = $this->registry->errorCodes();
        $enforcedFamilies = array_fill_keys($errorCodes['enforced_families'] ?? [], true);
        $sharedCodes = array_fill_keys($errorCodes['shared'] ?? [], true);
        $productCodes = $errorCodes['products'] ?? [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            $family = $this->stateFamilyForDirectory($familyDirectory, $stateFamilies);

            if ($family === null || ! isset($enforcedFamilies[$family])) {
                continue;
            }

            foreach ($this->jsonRendererFiles($familyDirectory) as $file) {
                $contents = file_get_contents($file) ?: '';
                $exampleCodes = [];

                foreach ($this->parser->parse($file, $contents) as $example) {
                    if (! $example->isValidArray() || ! is_array($example->decoded)) {
                        continue;
                    }

                    $code = $example->decoded['error']['code'] ?? null;

                    if (! is_string($code)) {
                        continue;
                    }

                    $exampleCodes[$code] = $example->line;
                    $finding = $this->findingForCode(
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

                array_push($findings, ...$this->tableFindings($file, $contents, $exampleCodes));
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, int>  $exampleCodes
     * @return list<Finding>
     */
    private function tableFindings(string $path, string $contents, array $exampleCodes): array
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

            $findings[] = $this->finding(
                $path,
                $line,
                "Error code `{$code}` is used by a JSON error example but is missing from the exhaustive `error.code` table.",
            );
        }

        foreach ($table['codes'] as $code) {
            if (isset($exampleCodes[$code])) {
                continue;
            }

            $findings[] = $this->finding(
                path: $path,
                line: $table['line'],
                message: "Error code `{$code}` is listed in the exhaustive `error.code` table but has no JSON error example.",
                severity: FindingSeverity::Warning,
            );
        }

        return $findings;
    }

    /**
     * @return array{codes: list<string>, line: int}|null
     */
    private function errorCodeTable(string $contents): ?array
    {
        if (
            preg_match('/^\|\s*`error\.code`\s*\|\s*(?<codes>.*?)\s*\|/m', $contents, $matches, PREG_OFFSET_CAPTURE)
            !== 1
        ) {
            return null;
        }

        $codes = array_values(array_filter(array_map(
            fn (string $code): string => trim($code, " \t\n\r\0\x0B`"),
            explode(',', $matches['codes'][0]),
        )));

        return [
            'codes' => $codes,
            'line' => $this->lineForOffset($contents, (int) $matches[0][1]),
        ];
    }

    /**
     * @param  array<string, array{singular: string, doctor_doc: ?string}>  $stateFamilies
     */
    private function stateFamilyForDirectory(string $familyDirectory, array $stateFamilies): ?string
    {
        $singular = $this->docs->familyName($familyDirectory);

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
    private function jsonRendererFiles(string $familyDirectory): array
    {
        $files = [];

        foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
            foreach ($this->docs->markdownFiles("{$commandDirectory}/technical", recursive: false) as $file) {
                if (preg_match('/^6\.2_.*_output-render_json\.md$/', basename($file)) === 1) {
                    $files[] = $file;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  array<string, true>  $sharedCodes
     * @param  array<string, list<string>>  $productCodes
     */
    private function findingForCode(
        string $path,
        int $line,
        string $code,
        array $sharedCodes,
        array $productCodes,
    ): ?Finding {
        if (! str_contains($code, '.')) {
            if (isset($sharedCodes[$code])) {
                return null;
            }

            return $this->finding($path, $line, "Error code `{$code}` is not a registered shared error code.");
        }

        [$product, $condition] = explode('.', $code, 2);

        if (! isset($productCodes[$product])) {
            return $this->finding($path, $line, "Error code `{$code}` uses unregistered product prefix `{$product}`.");
        }

        if (in_array($condition, $productCodes[$product], true)) {
            return null;
        }

        return $this->finding($path, $line, "Error code `{$code}` is not registered under product `{$product}`.");
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
            rule: 'command_docs.error_code_registry',
            message: $message,
        );
    }

    private function lineForOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }
}
