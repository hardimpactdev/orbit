<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ServiceTemplate
{
    /**
     * @param  array<string>  $versions  Available versions for this service
     * @param  array<string, mixed>  $configSchema  JSON schema for configuration validation
     * @param  array<string, mixed>  $dockerConfig  Docker container configuration
     * @param  array<string>  $dependsOn  Other services this service depends on
     * @param  bool  $required  Whether this service is required and cannot be disabled
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $category,
        public array $versions,
        public array $configSchema,
        public array $dockerConfig,
        public array $dependsOn = [],
        public bool $required = false,
    ) {}

    /**
     * Create from array data (e.g., from YAML/JSON).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            label: $data['label'] ?? '',
            description: $data['description'] ?? '',
            category: $data['category'] ?? 'other',
            versions: $data['versions'] ?? [],
            configSchema: $data['configSchema'] ?? [],
            dockerConfig: $data['dockerConfig'] ?? [],
            dependsOn: $data['dependsOn'] ?? [],
            required: $data['required'] ?? false,
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'description' => $this->description,
            'category' => $this->category,
            'versions' => $this->versions,
            'configSchema' => $this->configSchema,
            'dockerConfig' => $this->dockerConfig,
            'dependsOn' => $this->dependsOn,
            'required' => $this->required,
        ];
    }

    /**
     * Check if this service depends on another service.
     */
    public function dependsOn(string $serviceName): bool
    {
        return in_array($serviceName, $this->dependsOn, true);
    }

    /**
     * Get the default version (first in versions array).
     */
    public function getDefaultVersion(): ?string
    {
        return $this->versions[0] ?? null;
    }

    /**
     * Check if a version is supported.
     */
    public function supportsVersion(string $version): bool
    {
        return in_array($version, $this->versions, true);
    }

    /**
     * Check if this service is required and cannot be disabled.
     */
    public function isRequired(): bool
    {
        return $this->required;
    }
}
