<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class RendererPrimitiveReferenceRule implements CommandDocsLintRule
{
    /**
     * Map of primitive identifier (as named in renderer/input-mode docs) to its
     * authoritative ux/ page, relative to docs/commands/ux/.
     */
    private const array PRIMITIVE_MAP = [
        'text' => 'inputs/text-prompt.md',
        'password' => 'inputs/password-prompt.md',
        'confirm' => 'inputs/confirm-prompt.md',
        'select' => 'inputs/select-prompt.md',
        'multiselect' => 'inputs/multi-select-prompt.md',
        'search' => 'inputs/search-prompt.md',
        'multisearch' => 'inputs/multi-search-prompt.md',
        'suggest' => 'inputs/suggest-prompt.md',
        'datatable' => 'lists/data-table-prompt.md',
        'table' => 'lists/table.md',
        'progress-tree' => 'progress/progress-tree.md',
        'spinner' => 'progress/spinner.md',
    ];

    private const array BANNED_METHODS = [
        '$this->table',
        '$this->ask',
        '$this->confirm',
        '$this->choice',
        '$this->secret',
    ];

    public function id(): string
    {
        return 'command_docs.renderer_primitive_reference';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->commandDirectories($familyDirectory) as $commandDirectory) {
                $technical = "{$commandDirectory}/technical";

                if (! is_dir($technical)) {
                    continue;
                }

                foreach ($context->technicalMarkdownFiles($technical) as $file) {
                    $name = basename($file);

                    if (preg_match('/^6\.[12]_.*_output-render_(human|json)\.md$/', $name) === 1) {
                        $findings = [
                            ...$findings,
                            ...$this->checkRendererFile($context, $file),
                        ];

                        continue;
                    }

                    if (preg_match('/^5\.1_.*_input-mode_interactive\.md$/', $name) === 1) {
                        $findings = [
                            ...$findings,
                            ...$this->checkInputModeFile($context, $file),
                        ];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkRendererFile(CommandDocsLintContext $context, string $file): array
    {
        $contents = $context->read($file);
        $findings = $this->bannedMethodFindings($context, $file, $contents);

        $section = $this->section($contents, 'Primitive');

        if ($section === null) {
            $findings[] = new CommandDocsLintFinding(
                path: $context->relativePath($file),
                ruleId: $this->id(),
                message: 'Renderer files must include a "## Primitive" section that names the primitive (linking to docs/commands/ux/lists/ or docs/commands/ux/progress/) or explicitly declares "None." with a reason.',
            );

            return $findings;
        }

        $body = trim($section['text']);

        if ($this->declaresNone($body)) {
            return $findings;
        }

        if (! $this->containsRendererPrimitiveLink($body)) {
            $findings[] = new CommandDocsLintFinding(
                path: $context->relativePath($file),
                ruleId: $this->id(),
                message: 'The "## Primitive" section must link to a primitive page under docs/commands/ux/lists/ or docs/commands/ux/progress/, or declare "None." with a one-line reason.',
                line: $section['line'],
            );
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function checkInputModeFile(CommandDocsLintContext $context, string $file): array
    {
        $contents = $context->read($file);
        $findings = $this->bannedMethodFindings($context, $file, $contents);

        $promptMapping = $this->section($contents, 'Prompt Mapping');

        if ($promptMapping === null) {
            return $findings;
        }

        $primitives = $this->primitivesIn($promptMapping['text']);

        foreach ($primitives as $primitive => $line) {
            if (! array_key_exists($primitive, self::PRIMITIVE_MAP)) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: sprintf(
                        'Unknown prompt primitive "%s". Use one of: %s.',
                        $primitive,
                        implode(', ', array_keys(self::PRIMITIVE_MAP)),
                    ),
                    line: $promptMapping['line'] + $line,
                );

                continue;
            }

            if (! str_contains($contents, self::PRIMITIVE_MAP[$primitive])) {
                $findings[] = new CommandDocsLintFinding(
                    path: $context->relativePath($file),
                    ruleId: $this->id(),
                    message: sprintf(
                        'Prompt primitive "%s" must link to docs/commands/ux/%s somewhere in this file.',
                        $primitive,
                        self::PRIMITIVE_MAP[$primitive],
                    ),
                    line: $promptMapping['line'] + $line,
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    private function bannedMethodFindings(CommandDocsLintContext $context, string $file, string $contents): array
    {
        $findings = [];

        foreach (self::BANNED_METHODS as $method) {
            $offset = strpos($contents, $method);

            if ($offset === false) {
                continue;
            }

            $findings[] = new CommandDocsLintFinding(
                path: $context->relativePath($file),
                ruleId: $this->id(),
                message: sprintf(
                    'Renderer and input-mode docs must not reference Symfony Console method `%s`. Use the matching primitive in docs/commands/ux/.',
                    $method,
                ),
                line: $this->lineForOffset($contents, $offset),
            );
        }

        return $findings;
    }

    private function declaresNone(string $body): bool
    {
        $firstLine = strtok($body, "\n") ?: '';

        return preg_match('/^\s*-?\s*None\.\s*(\S.*)?$/', trim($firstLine)) === 1;
    }

    private function containsRendererPrimitiveLink(string $body): bool
    {
        return preg_match('#ux/(lists|progress)/[a-z][a-z0-9-]*\.md#', $body) === 1;
    }

    /**
     * @return array<string, int> primitive name => line offset within section
     */
    private function primitivesIn(string $section): array
    {
        $primitives = [];
        $lines = preg_split('/\R/', $section) ?: [];
        $multiColumnIndex = null;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if (preg_match('/^\|\s*Primitive\s*\|\s*`?(?<name>[a-z][a-z-]*)`?\s*\|/', $line, $matches) === 1) {
                $primitives[$matches['name']] ??= $index;

                continue;
            }

            if ($multiColumnIndex === null) {
                $headerIndex = $this->primitiveColumnIndex($trimmed);

                if ($headerIndex !== null) {
                    $multiColumnIndex = $headerIndex;
                }

                continue;
            }

            if (! str_starts_with($trimmed, '|') || str_contains($trimmed, '---')) {
                continue;
            }

            $cell = $this->cellAt($trimmed, $multiColumnIndex);

            if ($cell === null) {
                continue;
            }

            if (preg_match('/^`?(?<name>[a-z][a-z-]*)`?$/', $cell, $matches) === 1) {
                $primitives[$matches['name']] ??= $index;
            }
        }

        return $primitives;
    }

    private function primitiveColumnIndex(string $headerLine): ?int
    {
        if (! str_starts_with($headerLine, '|')) {
            return null;
        }

        $cells = $this->cells($headerLine);

        foreach ($cells as $index => $cell) {
            if (strcasecmp($cell, 'Primitive') === 0) {
                return $index;
            }
        }

        return null;
    }

    private function cellAt(string $line, int $index): ?string
    {
        $cells = $this->cells($line);

        return $cells[$index] ?? null;
    }

    /**
     * @return list<string>
     */
    private function cells(string $line): array
    {
        $line = trim($line);
        $line = trim($line, '|');
        $cells = explode('|', $line);

        return array_values(array_map('trim', $cells));
    }

    /**
     * @return array{text: string, line: int}|null
     */
    private function section(string $contents, string $heading): ?array
    {
        $pattern = '/^## '.preg_quote($heading, '/').'\s*$(?<section>.*?)(?:^## |\z)/ms';

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return [
            'text' => $matches['section'][0],
            'line' => $this->lineForOffset($contents, $matches[0][1]),
        ];
    }

    private function lineForOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }
}
