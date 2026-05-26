<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2ETopologyArtifactNamespace
{
    public const string EnvironmentVariable = 'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE';

    public static function current(): string
    {
        $namespace = getenv(self::EnvironmentVariable);

        if (is_string($namespace) && trim($namespace) !== '') {
            return self::sanitize($namespace);
        }

        return 'prepared';
    }

    public static function dockerImageSlug(string $slug): string
    {
        $namespace = self::current();

        return "{$namespace}-{$slug}";
    }

    public static function dockerRuntimeImage(string $repository): string
    {
        $namespace = self::current();

        return "{$repository}:{$namespace}-current";
    }

    public static function dockerBuildName(string $instancePrefix, E2ETopologyKind $kind): string
    {
        $namespace = self::current();

        return "{$instancePrefix}-{$namespace}-build-{$kind->value}";
    }

    public static function runtimeInstancePrefix(string $instancePrefix): string
    {
        $namespace = self::current();

        return "{$instancePrefix}-{$namespace}";
    }

    public static function incusTemplateName(string $name): string
    {
        $namespace = self::current();

        if (! str_starts_with($name, 'orbit-template-')) {
            return $name;
        }

        return 'orbit-template-'.$namespace.'-'.substr($name, strlen('orbit-template-'));
    }

    public static function incusSnapshotName(E2ETopologyKind $kind): string
    {
        $namespace = self::current();

        return "clean-{$namespace}-{$kind->value}";
    }

    private static function sanitize(string $namespace): string
    {
        $namespace = strtolower(trim($namespace));
        $namespace = preg_replace('/[^a-z0-9.-]+/', '-', $namespace) ?? '';
        $namespace = trim($namespace, '.-');

        if ($namespace === '') {
            throw new \InvalidArgumentException(self::EnvironmentVariable.' must contain at least one alphanumeric character.');
        }

        return $namespace;
    }
}
