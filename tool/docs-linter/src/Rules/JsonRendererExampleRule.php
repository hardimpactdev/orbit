<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;
use OrbitDocsLinter\CommandDocsRegistry;
use OrbitDocsLinter\JsonExample;
use OrbitDocsLinter\JsonExampleParser;

final class JsonRendererExampleRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.json_renderer_examples';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];
        $schemas = (new CommandDocsRegistry($context->repositoryRoot))->entitySchemas();
        $parser = new JsonExampleParser;

        foreach ($this->jsonRendererFiles($context) as $file) {
            foreach ($parser->parse($file, $context->read($file)) as $example) {
                if (! $example->isValidArray()) {
                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
                        message: sprintf('JSON example %d is not valid JSON: %s.', $example->blockIndex + 1, $example->parseError ?? 'decoded value is not an object'),
                        line: $example->line,
                    );

                    continue;
                }

                $decoded = $example->decoded;
                $hasSuccess = array_key_exists('success', $decoded);
                $hasError = array_key_exists('error', $decoded);

                if ($hasSuccess && $hasError) {
                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
                        message: sprintf('JSON example %d must not contain both top-level envelopes: success and error.', $example->blockIndex + 1),
                        line: $example->line,
                    );
                }

                if ($hasSuccess && ! is_array($decoded['success'])) {
                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
                        message: sprintf('JSON example %d must use a success object, not a scalar success value.', $example->blockIndex + 1),
                        line: $example->line,
                    );
                }

                if ($hasError && ! is_array($decoded['error'])) {
                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
                        message: sprintf('JSON example %d must use an error object, not a scalar error value.', $example->blockIndex + 1),
                        line: $example->line,
                    );
                }

                foreach ($this->nestedBooleanSuccessPaths($decoded) as $path) {
                    if ($path === 'success') {
                        continue;
                    }

                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
                        message: sprintf('JSON example %d contains nested boolean "%s"; success is an envelope, not a data field.', $example->blockIndex + 1, $path),
                        line: $example->line,
                    );
                }

                array_push($findings, ...$this->entityShapeFindings($context, $file, $example, $decoded, $schemas));
            }
        }

        return $findings;
    }

    /**
     * @param  array<mixed>  $decoded
     * @param  array<string, array{required?: array<string, string>, optional?: array<string, string>}>  $schemas
     * @return list<CommandDocsLintFinding>
     */
    private function entityShapeFindings(CommandDocsLintContext $context, string $file, JsonExample $example, array $decoded, array $schemas): array
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
                            context: $context,
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
                    context: $context,
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
     * @return list<CommandDocsLintFinding>
     */
    private function validateEntity(
        CommandDocsLintContext $context,
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
        $allowed = array_merge($required, $optional);

        foreach ($required as $field => $type) {
            if (array_key_exists($field, $value)) {
                continue;
            }

            $findings[] = new CommandDocsLintFinding(
                path: $context->relativePath($file),
                ruleId: $this->id(),
                message: 'JSON example '.($example->blockIndex + 1)." {$label} is missing required canonical {$entity} field `{$field}`.",
                line: $example->line,
            );
        }

        foreach ($allowed as $field => $type) {
            if (! array_key_exists($field, $value)) {
                continue;
            }

            if ($this->matchesType($value[$field], $type)) {
                continue;
            }

            $findings[] = new CommandDocsLintFinding(
                path: $context->relativePath($file),
                ruleId: $this->id(),
                message: 'JSON example '.($example->blockIndex + 1)." {$label}.{$field} must be {$type}, got {$this->typeName($value[$field])}.",
                line: $example->line,
            );
        }

        foreach ($value as $field => $_) {
            if (array_key_exists($field, $allowed)) {
                continue;
            }

            $findings[] = new CommandDocsLintFinding(
                path: $context->relativePath($file),
                ruleId: $this->id(),
                message: 'JSON example '.($example->blockIndex + 1)." {$label} contains non-canonical {$entity} field `{$field}`.",
                severity: CommandDocsLintSeverity::Warning,
                line: $example->line,
            );
        }

        return $findings;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        foreach (explode('|', $type) as $candidate) {
            if ($this->matchesSingleType($value, $candidate)) {
                return true;
            }
        }

        return false;
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
}
