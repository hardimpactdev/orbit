<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\JsonExampleParser;
use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class NextActionContractRule implements GroupedRule
{
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
            $contents = $this->docs->contents($file);

            foreach ($this->parser->parse($file, $contents) as $example) {
                if (! $example->isValidArray() || ! is_array($example->decoded)) {
                    continue;
                }

                foreach ($this->actionFields($example->decoded) as $field) {
                    $path = implode('.', $field['path']);

                    if ($field['key'] === 'next_steps') {
                        if ($path !== 'success.data.next_steps') {
                            $findings[] = $this->finding(
                                $file,
                                'JSON example '
                                .($example->blockIndex + 1)
                                ." uses next_steps at {$path}; next_steps is only allowed at success.data.next_steps.",
                                $example->line,
                            );

                            continue;
                        }

                        if (! $this->isStringList($field['value'])) {
                            $findings[] = $this->finding(
                                $file,
                                'JSON example '
                                .($example->blockIndex + 1)
                                .' success.data.next_steps must be an array of prose strings.',
                                $example->line,
                            );
                        }

                        if (! str_contains($contents, 'human onboarding checklist')) {
                            $findings[] = $this->finding(
                                $file,
                                'JSON renderer files that expose success.data.next_steps must document it as a human onboarding checklist.',
                                $example->line,
                            );
                        }

                        continue;
                    }

                    if ($this->isAllowedNextCommandPath($field['path'])) {
                        continue;
                    }

                    $findings[] = $this->finding(
                        $file,
                        'JSON example '
                        .($example->blockIndex + 1)
                        ." uses next_command at {$path}; next_command is only allowed on warning or error recovery metadata.",
                        $example->line,
                    );
                }
            }
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

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $path
     * @return list<array{key: string, path: list<string>, value: mixed}>
     */
    private function actionFields(array $value, array $path = []): array
    {
        $fields = [];

        foreach ($value as $key => $child) {
            $key = (string) $key;
            $childPath = [...$path, $key];

            if ($key === 'next_steps' || $key === 'next_command') {
                $fields[] = [
                    'key' => $key,
                    'path' => $childPath,
                    'value' => $child,
                ];
            }

            if (is_array($child)) {
                array_push($fields, ...$this->actionFields($child, $childPath));
            }
        }

        return $fields;
    }

    /**
     * @param  list<string>  $path
     */
    private function isAllowedNextCommandPath(array $path): bool
    {
        if ($path === ['error', 'meta', 'next_command']) {
            return true;
        }

        return (
            count($path) === 5
            && $path[0] === 'success'
            && $path[1] === 'meta'
            && $path[2] === 'warnings'
            && ctype_digit($path[3])
            && $path[4] === 'next_command'
            || count($path) === 6
            && $path[0] === 'success'
            && $path[1] === 'meta'
            && $path[2] === 'doctor'
            && $path[3] === 'failures'
            && ctype_digit($path[4])
            && $path[5] === 'next_command'
        );
    }

    private function isStringList(mixed $value): bool
    {
        return (
            is_array($value)
            && array_is_list($value)
            && array_all($value, static fn (mixed $item): bool => is_string($item))
        );
    }

    private function finding(string $path, string $message, ?int $line = null): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: $line,
            severity: FindingSeverity::Error,
            rule: 'command_docs.next_action_contract',
            message: $message,
        );
    }
}
