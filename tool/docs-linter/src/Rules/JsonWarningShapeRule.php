<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\JsonExampleParser;

final class JsonWarningShapeRule implements CommandDocsLintRule
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

    public function id(): string
    {
        return 'command_docs.json_warning_shape';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];
        $parser = new JsonExampleParser;

        foreach ($this->jsonRendererFiles($context) as $file) {
            $contents = $context->read($file);

            if (str_contains($contents, 'success.data.drift[]') || str_contains($contents, 'success.data.drift')) {
                $findings[] = $this->finding($context, $file, 'Partial-success drift must be documented under success.meta.warnings[], not success.data.drift.');
            }

            if (str_contains($contents, 'success.data.handoffs[]') || str_contains($contents, 'success.data.handoffs')) {
                $findings[] = $this->finding($context, $file, 'Partial-success handoffs must be documented under success.meta.warnings[], not success.data.handoffs.');
            }

            $mentionsWarningsPath = str_contains($contents, 'success.meta.warnings');
            foreach ($parser->parse($file, $contents) as $example) {
                if (! $example->isValidArray()) {
                    continue;
                }

                foreach ($this->warningEntries($example->decoded) as $warningIndex => $warning) {
                    if (! is_array($warning)) {
                        $findings[] = $this->finding(
                            $context,
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
                            $context,
                            $file,
                            sprintf('JSON example %d warning %d is missing %s.', $example->blockIndex + 1, $warningIndex + 1, $field),
                            $example->line,
                        );
                    }
                }
            }

            if (! $mentionsWarningsPath) {
                continue;
            }

            foreach (self::REQUIRED_WARNING_FIELDS as $field) {
                if ($this->documentsWarningField($contents, $field)) {
                    continue;
                }

                $findings[] = $this->finding(
                    $context,
                    $file,
                    "JSON renderer files that document success.meta.warnings[] must document each warning's {$field} field.",
                );
            }
        }

        return $findings;
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

    private function finding(CommandDocsLintContext $context, string $path, string $message, ?int $line = null): CommandDocsLintFinding
    {
        return new CommandDocsLintFinding(
            path: $context->relativePath($path),
            ruleId: $this->id(),
            message: $message,
            line: $line,
        );
    }
}
