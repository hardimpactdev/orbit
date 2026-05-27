<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\CommandDocsRegistry;
use App\Librarian\JsonExample;
use App\Librarian\JsonExampleParser;
use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class JsonRendererExampleRule implements GroupedRule
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
        $schemas = $this->registry->entitySchemas();

        foreach ($this->jsonRendererFiles() as $file) {
            foreach ($this->parser->parse($file, file_get_contents($file) ?: '') as $example) {
                if (! $example->isValidArray() || ! is_array($example->decoded)) {
                    $findings[] = $this->finding(
                        $file,
                        sprintf('JSON example %d is not valid JSON: %s.', $example->blockIndex + 1, $example->parseError ?? 'decoded value is not an object'),
                        $example->line,
                    );

                    continue;
                }

                array_push(
                    $findings,
                    ...$this->envelopeFindings($file, $example, $example->decoded),
                    ...$this->entityShapeFindings($file, $example, $example->decoded, $schemas),
                );
            }
        }

        return $findings;
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<Finding>
     */
    private function envelopeFindings(string $file, JsonExample $example, array $decoded): array
    {
        $findings = [];
        $hasSuccess = array_key_exists('success', $decoded);
        $hasError = array_key_exists('error', $decoded);

        if ($hasSuccess && $hasError) {
            $findings[] = $this->finding(
                $file,
                sprintf('JSON example %d must not contain both top-level envelopes: success and error.', $example->blockIndex + 1),
                $example->line,
            );
        }

        if ($hasSuccess && ! is_array($decoded['success'])) {
            $findings[] = $this->finding(
                $file,
                sprintf('JSON example %d must use a success object, not a scalar success value.', $example->blockIndex + 1),
                $example->line,
            );
        }

        if ($hasError && ! is_array($decoded['error'])) {
            $findings[] = $this->finding(
                $file,
                sprintf('JSON example %d must use an error object, not a scalar error value.', $example->blockIndex + 1),
                $example->line,
            );
        }

        foreach ($this->nestedBooleanSuccessPaths($decoded) as $path) {
            if ($path === 'success') {
                continue;
            }

            $findings[] = $this->finding(
                $file,
                sprintf('JSON example %d contains nested boolean "%s"; success is an envelope, not a data field.', $example->blockIndex + 1, $path),
                $example->line,
            );
        }

        return $findings;
    }

    /**
     * @param  array<mixed>  $decoded
     * @param  array<string, array{required?: array<string, string>, optional?: array<string, string>}>  $schemas
     * @return list<Finding>
     */
    private function entityShapeFindings(string $file, JsonExample $example, array $decoded, array $schemas): array
    {
        $findings = [];

        foreach ($this->entityPaths() as $entityPath) {
            $schema = $schemas[$entityPath['entity']] ?? null;

            if ($schema === null) {
                continue;
            }

            $value = $this->valueAtPath($decoded, $entityPath['path']);

            if (! is_array($value)) {
                continue;
            }

            if ($entityPath['list']) {
                if (! array_is_list($value)) {
                    continue;
                }

                foreach ($value as $index => $item) {
                    if (! $this->isJsonObject($item)) {
                        continue;
                    }

                    array_push(
                        $findings,
                        ...$this->validateEntity(
                            file: $file,
                            example: $example,
                            entity: $entityPath['entity'],
                            label: "{$entityPath['label']}[{$index}]",
                            value: $item,
                            schema: $schema,
                        ),
                    );
                }

                continue;
            }

            if (! $this->isJsonObject($value)) {
                continue;
            }

            array_push(
                $findings,
                ...$this->validateEntity(
                    file: $file,
                    example: $example,
                    entity: $entityPath['entity'],
                    label: $entityPath['label'],
                    value: $value,
                    schema: $schema,
                ),
            );
        }

        return $findings;
    }

    /**
     * @return list<array{entity: string, path: list<string>, label: string, list: bool}>
     */
    private function entityPaths(): array
    {
        return [
            ['entity' => 'app', 'path' => ['success', 'data', 'app'], 'label' => 'success.data.app', 'list' => false],
            ['entity' => 'app', 'path' => ['success', 'data', 'apps'], 'label' => 'success.data.apps', 'list' => true],
            ['entity' => 'workspace', 'path' => ['success', 'data', 'workspace'], 'label' => 'success.data.workspace', 'list' => false],
            ['entity' => 'workspace', 'path' => ['success', 'data', 'workspaces'], 'label' => 'success.data.workspaces', 'list' => true],
            ['entity' => 'node', 'path' => ['success', 'data', 'node'], 'label' => 'success.data.node', 'list' => false],
            ['entity' => 'node', 'path' => ['success', 'data', 'nodes'], 'label' => 'success.data.nodes', 'list' => true],
            ['entity' => 'process', 'path' => ['success', 'data', 'process'], 'label' => 'success.data.process', 'list' => false],
            ['entity' => 'process', 'path' => ['success', 'data', 'processes'], 'label' => 'success.data.processes', 'list' => true],
        ];
    }

    /**
     * @param  array<mixed>  $decoded
     * @param  list<string>  $path
     */
    private function valueAtPath(array $decoded, array $path): mixed
    {
        $value = $decoded;

        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function isJsonObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array{required?: array<string, string>, optional?: array<string, string>}  $schema
     * @return list<Finding>
     */
    private function validateEntity(
        string $file,
        JsonExample $example,
        string $entity,
        string $label,
        array $value,
        array $schema,
    ): array {
        $findings = [];
        $required = $schema['required'] ?? [];
        $optional = $schema['optional'] ?? [];
        $allowed = array_merge(
            $required,
            $optional,
            $this->commandSpecificEntityFields($this->docs->relativePath($file), $entity, $label),
        );

        foreach ($required as $field => $type) {
            if (array_key_exists($field, $value)) {
                continue;
            }

            $findings[] = $this->finding(
                $file,
                'JSON example '.($example->blockIndex + 1)." {$label} is missing required canonical {$entity} field `{$field}`.",
                $example->line,
            );
        }

        foreach ($allowed as $field => $type) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            if ($this->matchesType($value[$field], $type)) {
                continue;
            }

            $findings[] = $this->finding(
                $file,
                'JSON example '.($example->blockIndex + 1)." {$label}.{$field} must be {$type}, got {$this->typeName($value[$field])}.",
                $example->line,
            );
        }

        foreach ($value as $field => $_) {
            if (array_key_exists($field, $allowed)) {
                continue;
            }

            $findings[] = $this->finding(
                $file,
                'JSON example '.($example->blockIndex + 1)." {$label} contains non-canonical {$entity} field `{$field}`.",
                $example->line,
                FindingSeverity::Warning,
            );
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function commandSpecificEntityFields(string $path, string $entity, string $label): array
    {
        if (
            $path === 'content/domains/5_app/3_app-list/technical/6.2_app-list_output-render_json.md'
            && $entity === 'app'
            && str_starts_with($label, 'success.data.apps[')
        ) {
            return ['workspaces' => 'array'];
        }

        return [];
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return array_any(explode('|', $type), fn ($candidate) => $this->matchesSingleType($value, $candidate));
    }

    private function matchesSingleType(mixed $value, string $type): bool
    {
        return match ($type) {
            'array' => is_array($value) && array_is_list($value),
            'bool' => is_bool($value),
            'int' => is_int($value),
            'null' => $value === null,
            'object' => $this->isJsonObject($value),
            'string' => is_string($value),
            default => false,
        };
    }

    private function typeName(mixed $value): string
    {
        if ($this->isJsonObject($value)) {
            return 'object';
        }

        if (is_array($value)) {
            return 'array';
        }

        return get_debug_type($value);
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
     * @return list<string>
     */
    private function nestedBooleanSuccessPaths(array $value, string $prefix = ''): array
    {
        $paths = [];

        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if ($key === 'success' && is_bool($child)) {
                $paths[] = $path;

                continue;
            }

            if (is_array($child)) {
                array_push($paths, ...$this->nestedBooleanSuccessPaths($child, $path));
            }
        }

        return $paths;
    }

    private function finding(string $path, string $message, ?int $line = null, FindingSeverity $severity = FindingSeverity::Error): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: $line,
            severity: $severity,
            rule: 'command_docs.json_renderer_examples',
            message: $message,
        );
    }
}
