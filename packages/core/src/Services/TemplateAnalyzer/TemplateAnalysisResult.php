<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\TemplateAnalyzer;

/**
 * Data transfer object for template analysis results.
 */
final readonly class TemplateAnalysisResult
{
    /**
     * @param  array<string, string|null>  $drivers  The detected driver configurations
     * @param  array<string, mixed>  $metadata  Additional metadata about the template
     */
    public function __construct(
        public string $type,
        public array $drivers,
        public array $metadata = [],
    ) {}

    /**
     * Create a result for a Laravel template.
     *
     * @param  array<string, string|null>  $drivers
     * @param  array<string, mixed>  $metadata
     */
    public static function laravel(array $drivers, array $metadata = []): self
    {
        return new self('laravel', $drivers, $metadata);
    }

    /**
     * Create an empty/unknown result.
     */
    public static function unknown(): self
    {
        return new self('unknown', [
            'db_driver' => null,
            'session_driver' => null,
            'cache_driver' => null,
            'queue_driver' => null,
        ]);
    }

    /**
     * Convert to array for JSON response.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'drivers' => $this->drivers,
            'metadata' => $this->metadata,
        ];
    }
}
