<?php

declare(strict_types=1);

namespace App\Docs\Librarian;

final readonly class CommandDocsRegistry
{
    /**
     * @return array<string, array{singular: string, doctor_doc: ?string}>
     */
    public function stateFamilies(): array
    {
        return $this->load('state_families.php');
    }

    /**
     * @return array<string, array{family?: ?string, kind?: string, allowed_next_commands?: list<string>}>
     */
    public function warningCodes(): array
    {
        return $this->load('warning_codes.php');
    }

    /**
     * @return array{enforced_families?: list<string>, shared?: list<string>, products?: array<string, list<string>>}
     */
    public function errorCodes(): array
    {
        return $this->load('error_codes.php');
    }

    /**
     * @return array<string, array{required?: array<string, string>, optional?: array<string, string>}>
     */
    public function entitySchemas(): array
    {
        $directory = "{$this->registryRoot()}/entities";

        if (! is_dir($directory)) {
            return [];
        }

        $schemas = [];

        foreach (glob("{$directory}/*.php") ?: [] as $file) {
            $schema = require $file;

            if (! is_array($schema)) {
                continue;
            }

            $schemas[basename($file, '.php')] = $schema;
        }

        ksort($schemas);

        return $schemas;
    }

    /**
     * @return array{contexts?: array<string, array{markers?: list<string>}>, options?: array<string, array{allowed_contexts?: list<string>, allowed_command_families?: list<string>, public_wording?: string}>}
     */
    public function sharedOptions(): array
    {
        return $this->load('shared_options.php');
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $file): array
    {
        $path = "{$this->registryRoot()}/{$file}";

        if (! is_file($path)) {
            return [];
        }

        $registry = require $path;

        if (! is_array($registry)) {
            return [];
        }

        return $registry;
    }

    private function registryRoot(): string
    {
        return config_path('librarian-command-docs');
    }
}
