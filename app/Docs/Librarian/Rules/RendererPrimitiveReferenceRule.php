<?php

declare(strict_types=1);

namespace App\Docs\Librarian\Rules;

use App\Docs\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class RendererPrimitiveReferenceRule implements GroupedRule
{
    /**
     * @var array<string, string>
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

    /**
     * @var list<string>
     */
    private const array BANNED_METHODS = [
        '$this->table',
        '$this->ask',
        '$this->confirm',
        '$this->choice',
        '$this->secret',
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
                foreach ($this->docs->markdownFiles("{$commandDirectory}/technical", recursive: false) as $file) {
                    $name = basename($file);

                    if (preg_match('/^6\.[12]_.*_output-render_(human|json)\.md$/', $name) === 1) {
                        array_push($findings, ...$this->checkRendererFile($file));

                        continue;
                    }

                    if (preg_match('/^5\.1_.*_input-mode_interactive\.md$/', $name) === 1) {
                        array_push($findings, ...$this->checkInputModeFile($file));
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkRendererFile(string $file): array
    {
        $contents = file_get_contents($file) ?: '';
        $findings = $this->bannedMethodFindings($file, $contents);
        $section = $this->section($contents, 'Primitive');

        if ($section === null) {
            $findings[] = $this->finding(
                $file,
                'Renderer files must include a "## Primitive" section that names the primitive (linking to docs/ux/commands/lists/ or docs/ux/commands/progress/) or explicitly declares "None." with a reason.',
            );

            return $findings;
        }

        $body = trim($section['text']);

        if ($this->declaresNone($body)) {
            return $findings;
        }

        if (! $this->containsRendererPrimitiveLink($body)) {
            $findings[] = $this->finding(
                $file,
                'The "## Primitive" section must link to a primitive page under docs/ux/commands/lists/ or docs/ux/commands/progress/, or declare "None." with a one-line reason.',
                $section['line'],
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkInputModeFile(string $file): array
    {
        $contents = file_get_contents($file) ?: '';
        $findings = $this->bannedMethodFindings($file, $contents);
        $promptMapping = $this->section($contents, 'Prompt Mapping');

        if ($promptMapping === null) {
            return $findings;
        }

        foreach ($this->primitivesIn($promptMapping['text']) as $primitive => $line) {
            if (! array_key_exists($primitive, self::PRIMITIVE_MAP)) {
                $findings[] = $this->finding(
                    $file,
                    sprintf('Unknown prompt primitive "%s". Use one of: %s.', $primitive, implode(', ', array_keys(self::PRIMITIVE_MAP))),
                    $promptMapping['line'] + $line,
                );

                continue;
            }

            if (str_contains($contents, self::PRIMITIVE_MAP[$primitive])) {
                continue;
            }

            $findings[] = $this->finding(
                $file,
                sprintf('Prompt primitive "%s" must link to docs/ux/commands/%s somewhere in this file.', $primitive, self::PRIMITIVE_MAP[$primitive]),
                $promptMapping['line'] + $line,
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function bannedMethodFindings(string $file, string $contents): array
    {
        $findings = [];

        foreach (self::BANNED_METHODS as $method) {
            $offset = strpos($contents, $method);

            if ($offset === false) {
                continue;
            }

            $findings[] = $this->finding(
                $file,
                sprintf('Renderer and input-mode docs must not reference Symfony Console method `%s`. Use the matching primitive in docs/ux/commands/.', $method),
                $this->lineForOffset($contents, $offset),
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
        return preg_match('#ux/commands/(lists|progress)/[a-z][a-z0-9-]*\.md#', $body) === 1;
    }

    /**
     * @return array<string, int>
     */
    private function primitivesIn(string $section): array
    {
        $primitives = [];
        $lines = preg_split('/\R/', $section) ?: [];
        $multiColumnIndex = null;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if (preg_match('/^\|\s*Primitive\s*\|\s*`?(?<name>[a-z][a-z-]*)`?\s*\|/', $line, $matches) === 1) {
                $primitives[(string) $matches['name']] ??= $index;

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
                $primitives[(string) $matches['name']] ??= $index;
            }
        }

        return $primitives;
    }

    private function primitiveColumnIndex(string $headerLine): ?int
    {
        if (! str_starts_with($headerLine, '|')) {
            return null;
        }

        foreach ($this->cells($headerLine) as $index => $cell) {
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

        return array_values(array_map(trim(...), $cells));
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

    private function finding(string $path, string $message, ?int $line = null): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: $line,
            severity: FindingSeverity::Error,
            rule: 'command_docs.renderer_primitive_reference',
            message: $message,
        );
    }
}
