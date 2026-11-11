<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\TaskVms\RecipeImageBuilds;

/** One recipe-owned executable rule for checkout migration, definitions and container unit rendering. */
final readonly class ContainerProcessCommands
{
    public function __construct(private RecipeImageBuilds $recipes) {}

    /** @param list<string> $command
     * @return list<string>
     */
    public function resolve(array $command, ?string $php, string $label, bool $hostPaths = false): array
    {
        $index = $this->commandIndex($command);
        if ($index === null) {
            return $command;
        }
        $path = $command[$index];
        if (($hostPaths && ! $this->hostCommand($path)) || (! $hostPaths && str_contains($path, '/'))) {
            return $command;
        }
        $resolved = $this->executable(basename($path), $php, $label.' executable ['.$path.']');
        if ($resolved !== null) {
            $command[$index] = $resolved;
        }

        return array_values($command);
    }

    /** Normalizes only executables the shared rule can prove; opaque argv stays byte-for-byte unchanged.
     * @param  list<string>  $command
     * @return list<string>
     */
    public function names(array $command, ?string $php, string $label): array
    {
        $index = $this->commandIndex($command);
        if ($index === null) {
            return $command;
        }
        $path = $command[$index];
        if (preg_match('~^/opt/orbit-runtime/current/php/([0-9]+[.][0-9]+)/bin/php$~', $path, $match) === 1) {
            $name = 'php'.$match[1];
        } elseif (dirname($path) === InstanceHostIntent::RuntimeFolder.'/current/bin' || $this->hostCommand($path) || ! str_contains($path, '/')) {
            $name = basename($path);
        } else {
            return $command;
        }
        if ($this->executable($name, $php, $label) !== null) {
            $command[$index] = $name;
        }

        return array_values($command);
    }

    public function supportsName(string $name): bool
    {
        if (str_contains($name, '/')) {
            return false;
        }
        if (preg_match('/^php(?:[0-9]+[.][0-9]+)?$/', $name) === 1) {
            return $name === 'php' || isset($this->recipes->currentRecipe()->instances->php[substr($name, 3)]);
        }

        return $this->executable($name, null, $name) !== null;
    }

    private function executable(string $name, ?string $php, string $label): ?string
    {
        $folders = $this->recipes->currentRecipe()->instances;
        if (preg_match('/^php(?<version>[0-9]+[.][0-9]+)?$/', $name, $match) === 1) {
            $version = $match['version'] ?? $php;
            if (! is_string($version) || ($folders->php[$version]->artifact ?? null) === null) {
                throw new ResourceOperationException('instance.migrate_process_binary_unavailable',
                    "{$label} executable [{$name}] requires PHP [{$version}], which the container runtime does not ship.", 409);
            }

            return InstanceHostIntent::RuntimeFolder.'/current/php/'.$version.'/bin/php';
        }
        foreach ($folders->runtime ?? [] as $part) {
            if (array_key_exists($name, $part->bin)) {
                return InstanceHostIntent::RuntimeFolder.'/current/bin/'.$name;
            }
        }

        return null;
    }

    /** Known host install locations, shared by every recipe command and versioned PHP name. */
    private function hostCommand(string $path): bool
    {
        return preg_match('~^(?:/(?:usr/bin|usr/local/bin|bin)/[^/]+|/(?:home/[^/]+|root)/[.]vite-plus/(?:bin/[^/]+|js_runtime/node/[^/]+/bin/node))$~', $path) === 1;
    }

    /** The actual command after env options and assignments. Shell strings and unknown options stay opaque.
     * @param  list<string>  $command
     */
    public function commandIndex(array $command): ?int
    {
        if (($command[0] ?? null) !== '/usr/bin/env') {
            return $command === [] ? null : 0;
        }
        $options = true;
        for ($index = 1; $index < count($command); $index++) {
            $argument = $command[$index];
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $argument) === 1) {
                continue;
            }
            if ($options && $argument === '--') {
                $options = false;

                continue;
            }
            if ($options && ($argument === '-i' || str_starts_with($argument, '--unset='))) {
                continue;
            }
            if ($options && $argument === '-u' && isset($command[$index + 1])) {
                $index++;

                continue;
            }
            if ($options && str_starts_with($argument, '-')) {
                return null;
            }

            return $index;
        }

        return null;
    }
}
