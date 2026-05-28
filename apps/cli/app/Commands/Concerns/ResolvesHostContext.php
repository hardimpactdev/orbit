<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Services\OrbitConfigStore;

trait ResolvesHostContext
{
    protected function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function hasMutuallyExclusiveOptions(string $first, string $second): bool
    {
        return $this->stringOption($first) !== null && $this->stringOption($second) !== null;
    }

    protected function targetNodeOptionOrDefault(): ?string
    {
        $node = $this->stringOption('node');

        if ($node !== null) {
            return $node;
        }

        if ($this->stringOption('app') !== null) {
            return null;
        }

        return app(OrbitConfigStore::class)->defaultNode();
    }

    protected function hostCwd(): ?string
    {
        $hostCwd = getenv('ORBIT_HOST_CWD');

        if (is_string($hostCwd) && trim($hostCwd) !== '') {
            return trim($hostCwd);
        }

        $cwd = getcwd();

        return is_string($cwd) && trim($cwd) !== '' ? trim($cwd) : null;
    }

    protected function appFromOrbitMarker(): ?string
    {
        $cwd = $this->hostCwd();

        if ($cwd === null) {
            return null;
        }

        while ($cwd !== '' && $cwd !== '/') {
            $candidate = "{$cwd}/.orbit/config";

            if (is_file($candidate)) {
                $data = json_decode((string) file_get_contents($candidate), associative: true);

                if (is_array($data) && is_string($data['app'] ?? null) && trim($data['app']) !== '') {
                    return trim($data['app']);
                }
            }

            $parent = dirname((string) $cwd);

            if ($parent === $cwd) {
                break;
            }

            $cwd = $parent;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function filledQuery(array $query): array
    {
        return array_filter($query, fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
