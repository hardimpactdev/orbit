<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\JsonExampleParser;
use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class JsonWarningShapeRule implements GroupedRule
{
    /**
     * @var list<string>
     */
    private const array REQUIRED_WARNING_FIELDS = [
        'code',
        'family',
        'message',
        'next_command',
    ];

    public function __construct(
        private OrbitCommandDocs $docs,
        private JsonExampleParser $parser,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->jsonRendererFiles() as $file) {
            array_push($findings, ...$this->checkFile($file));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkFile(string $file): array
    {
        $contents = file_get_contents($file) ?: '';
        $findings = [];

        if (str_contains($contents, 'success.data.drift[]') || str_contains($contents, 'success.data.drift')) {
            $findings[] = $this->finding($file, 'Partial-success drift must be documented under success.meta.warnings[], not success.data.drift.');
        }

        if (str_contains($contents, 'success.data.handoffs[]') || str_contains($contents, 'success.data.handoffs')) {
            $findings[] = $this->finding($file, 'Partial-success handoffs must be documented under success.meta.warnings[], not success.data.handoffs.');
        }

        $mentionsWarningsPath = str_contains($contents, 'success.meta.warnings');

        foreach ($this->parser->parse($file, $contents) as $example) {
            if (! $example->isValidArray() || ! is_array($example->decoded)) {
                continue;
            }

            foreach ($this->warningEntries($example->decoded) as $warningIndex => $warning) {
                if (! is_array($warning)) {
                    $findings[] = $this->finding(
                        $file,
                        sprintf('JSON example %d warning %d must be an object with code, family, message, and next_command.', $example->blockIndex + 1, $warningIndex + 1),
                        $example->line,
                    );

                    continue;
                }

                foreach (self::REQUIRED_WARNING_FIELDS as $field) {
                    if (array_key_exists($field, $warning)) {
                        continue;
                    }

                    $findings[] = $this->finding(
                        $file,
                        sprintf('JSON example %d warning %d is missing %s.', $example->blockIndex + 1, $warningIndex + 1, $field),
                        $example->line,
                    );
                }
            }
        }

        if (! $mentionsWarningsPath) {
            return $findings;
        }

        foreach (self::REQUIRED_WARNING_FIELDS as $field) {
            if ($this->documentsWarningField($contents, $field)) {
                continue;
            }

            $findings[] = $this->finding(
                $file,
                "JSON renderer files that document success.meta.warnings[] must document each warning's {$field} field.",
            );
        }

        return $findings;
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

    private function documentsWarningField(string $contents, string $field): bool
    {
        return preg_match('/warnings(?:\[\])?(?:\.[a-z_]+)?[^\\n`]*`'.preg_quote($field, '/').'`/i', $contents) === 1
            || preg_match('/`'.preg_quote($field, '/').'`\s*\|/i', $contents) === 1
            || preg_match('/"'.preg_quote($field, '/').'"\s*:/', $contents) === 1;
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<mixed>
     */
    private function warningEntries(array $decoded): array
    {
        $warnings = $decoded['success']['meta']['warnings'] ?? null;

        if (! is_array($warnings)) {
            return [];
        }

        return array_values($warnings);
    }

    private function finding(string $path, string $message, ?int $line = null): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: $line,
            severity: FindingSeverity::Error,
            rule: 'command_docs.json_warning_shape',
            message: $message,
        );
    }
}
