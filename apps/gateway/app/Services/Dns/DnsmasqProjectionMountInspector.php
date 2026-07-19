<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Illuminate\Support\Facades\Process;

final readonly class DnsmasqProjectionMountInspector
{
    public function isMounted(string $containerId, string $expectedSource, string $expectedDestination): bool
    {
        $result = Process::timeout(15)->run(sprintf(
            "docker inspect --format '{{json .Mounts}}' %s",
            escapeshellarg($containerId),
        ));

        if (! $result->successful()) {
            return false;
        }

        /** @var array<array-key, mixed>|bool|float|int|string|null $mounts */
        $mounts = json_decode(trim($result->output()), associative: true);

        if (! is_array($mounts)) {
            return false;
        }

        $expectedSource = $this->canonicalPath($expectedSource);

        return array_any(
            $mounts,
            fn (mixed $mount): bool => $this->matches(
                mount: $mount,
                expectedSource: $expectedSource,
                expectedDestination: $expectedDestination,
            ),
        );
    }

    private function matches(mixed $mount, string $expectedSource, string $expectedDestination): bool
    {
        if (! is_array($mount)) {
            return false;
        }

        if (! is_string($mount['Source'] ?? null)) {
            return false;
        }

        return (
            ($mount['Type'] ?? null) === 'bind'
            && $this->canonicalPath($mount['Source']) === $expectedSource
            && ($mount['Destination'] ?? null) === $expectedDestination
            && ($mount['RW'] ?? null) === false
        );
    }

    private function canonicalPath(string $path): string
    {
        $canonicalPath = realpath($path);

        return $canonicalPath === false
            ? rtrim(string: $path, characters: '/')
            : $canonicalPath;
    }
}
