<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ServiceTemplate;

final class ServiceConfigValidator
{
    /**
     * Validate user configuration against a service template schema.
     *
     * @param  array<string, mixed>  $config  User-provided configuration
     * @param  ServiceTemplate  $template  Service template with schema
     * @return array{valid: bool, errors: array<string>}
     */
    public function validate(array $config, ServiceTemplate $template): array
    {
        $errors = [];
        $schema = $template->configSchema;

        // Check required fields
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (! array_key_exists($field, $config)) {
                    $errors[] = "Required field '{$field}' is missing";
                }
            }
        }

        // Validate properties
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($config as $key => $value) {
                if (! isset($schema['properties'][$key])) {
                    // Unknown property - could be a warning but not an error
                    continue;
                }

                $propertySchema = $schema['properties'][$key];
                $fieldErrors = $this->validateProperty($key, $value, $propertySchema);
                $errors = array_merge($errors, $fieldErrors);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate a single property against its schema.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string>
     */
    protected function validateProperty(string $key, mixed $value, array $schema): array
    {
        $errors = [];

        // Type validation
        if (isset($schema['type'])) {
            $expectedType = $schema['type'];
            $actualType = gettype($value);

            // Map PHP types to JSON schema types
            $typeMap = [
                'integer' => 'number',
                'double' => 'number',
                'boolean' => 'boolean',
                'string' => 'string',
                'array' => 'array',
                'object' => 'object',
                'NULL' => 'null',
            ];

            $mappedType = $typeMap[$actualType] ?? $actualType;

            if ($mappedType !== $expectedType && ! ($expectedType === 'number' && in_array($mappedType, ['integer', 'number']))) {
                $errors[] = "Field '{$key}' must be of type {$expectedType}, got {$actualType}";
            }
        }

        // Enum validation
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            if (! in_array($value, $schema['enum'], true)) {
                $allowed = implode(', ', $schema['enum']);
                $errors[] = "Field '{$key}' must be one of: {$allowed}";
            }
        }

        // Min/max for numbers
        if (is_numeric($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "Field '{$key}' must be at least {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "Field '{$key}' must be at most {$schema['maximum']}";
            }
        }

        // String length validation
        if (is_string($value)) {
            if (isset($schema['minLength']) && strlen($value) < $schema['minLength']) {
                $errors[] = "Field '{$key}' must be at least {$schema['minLength']} characters";
            }
            if (isset($schema['maxLength']) && strlen($value) > $schema['maxLength']) {
                $errors[] = "Field '{$key}' must be at most {$schema['maxLength']} characters";
            }
        }

        // Pattern validation for strings
        if (is_string($value) && isset($schema['pattern'])) {
            if (! preg_match('/'.$schema['pattern'].'/', $value)) {
                $errors[] = "Field '{$key}' does not match the required pattern";
            }
        }

        return $errors;
    }

    /**
     * Apply default values from schema to user configuration.
     *
     * @param  array<string, mixed>  $config  User-provided configuration
     * @param  ServiceTemplate  $template  Service template with schema
     * @return array<string, mixed> Configuration with defaults applied
     */
    public function applyDefaults(array $config, ServiceTemplate $template): array
    {
        $schema = $template->configSchema;
        $result = $config;

        if (! isset($schema['properties']) || ! is_array($schema['properties'])) {
            return $result;
        }

        foreach ($schema['properties'] as $key => $propertySchema) {
            // If field is not set in config and has a default, apply it
            if (! array_key_exists($key, $result) && isset($propertySchema['default'])) {
                $result[$key] = $propertySchema['default'];
            }
        }

        return $result;
    }

    /**
     * Validate and apply defaults in one operation.
     *
     * @param  array<string, mixed>  $config
     * @return array{valid: bool, errors: array<string>, config: array<string, mixed>}
     */
    public function validateAndApplyDefaults(array $config, ServiceTemplate $template): array
    {
        $configWithDefaults = $this->applyDefaults($config, $template);
        $validation = $this->validate($configWithDefaults, $template);

        return [
            'valid' => $validation['valid'],
            'errors' => $validation['errors'],
            'config' => $configWithDefaults,
        ];
    }
}
