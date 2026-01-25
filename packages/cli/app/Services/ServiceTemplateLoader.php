<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ServiceTemplate;
use RuntimeException;

class ServiceTemplateLoader
{
    protected string $templatesPath;

    /**
     * @var array<string, ServiceTemplate>
     */
    protected array $loadedTemplates = [];

    public function __construct(?string $templatesPath = null)
    {
        if ($templatesPath === null) {
            // Use base_path if available (when running in app context)
            // Otherwise use a sensible default for tests
            $templatesPath = function_exists('base_path')
                ? base_path('stubs/templates')
                : getcwd().'/stubs/templates';
        }

        $this->templatesPath = $templatesPath;
    }

    /**
     * Load a specific service template by name.
     *
     * @throws RuntimeException if template not found or invalid
     */
    public function load(string $name): ServiceTemplate
    {
        // Check if already loaded
        if (isset($this->loadedTemplates[$name])) {
            return $this->loadedTemplates[$name];
        }

        $filePath = "{$this->templatesPath}/{$name}.json";

        if (! file_exists($filePath)) {
            throw new RuntimeException("Service template not found: {$name}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Failed to read template file: {$name}");
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON in template {$name}: ".json_last_error_msg());
        }

        $template = ServiceTemplate::fromArray($data);

        // Cache the loaded template
        $this->loadedTemplates[$name] = $template;

        return $template;
    }

    /**
     * Load all available service templates.
     *
     * @return array<string, ServiceTemplate>
     */
    public function loadAll(): array
    {
        if (! is_dir($this->templatesPath)) {
            return [];
        }

        $files = $this->getJsonFiles($this->templatesPath);
        $templates = [];

        foreach ($files as $filename) {
            $name = basename($filename, '.json');

            try {
                $templates[$name] = $this->load($name);
            } catch (RuntimeException) {
                // Skip invalid templates
                continue;
            }
        }

        return $templates;
    }

    /**
     * Get list of available template names.
     *
     * @return array<string>
     */
    public function getAvailable(): array
    {
        if (! is_dir($this->templatesPath)) {
            return [];
        }

        $files = $this->getJsonFiles($this->templatesPath);
        $names = [];

        foreach ($files as $filename) {
            $names[] = basename($filename, '.json');
        }

        return $names;
    }

    /**
     * Get JSON files from a directory.
     *
     * @return array<string>
     */
    protected function getJsonFiles(string $directory): array
    {
        $files = scandir($directory);
        if ($files === false) {
            return [];
        }

        $jsonFiles = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (str_ends_with($file, '.json')) {
                $jsonFiles[] = $file;
            }
        }

        return $jsonFiles;
    }

    /**
     * Clear the loaded templates cache.
     */
    public function clearCache(): void
    {
        $this->loadedTemplates = [];
    }

    /**
     * Check if a template exists.
     */
    public function exists(string $name): bool
    {
        return file_exists("{$this->templatesPath}/{$name}.json");
    }

    /**
     * Get templates by category.
     *
     * @return array<string, ServiceTemplate>
     */
    public function getByCategory(string $category): array
    {
        $allTemplates = $this->loadAll();

        return array_filter($allTemplates, fn (ServiceTemplate $template) => $template->category === $category);
    }
}
