<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2ETopologyArtifactNamespace
{
    public const string EnvironmentVariable = 'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE';

    public const string DockerRoleImageRepository = 'orbit-e2e';

    public const string DockerBaseArtifactSet = 'base';

    public const string BaseArtifactSet = 'base';

    public static function current(): string
    {
        $namespace = getenv(self::EnvironmentVariable);

        if (is_string($namespace) && trim($namespace) !== '') {
            return self::sanitize($namespace);
        }

        return 'prepared';
    }

    public static function dockerRoleArtifactSet(): string
    {
        return self::artifactSet();
    }

    public static function dockerRoleImage(string $role, ?string $artifactSet = null): string
    {
        $artifactSet ??= self::dockerRoleArtifactSet();

        return self::DockerRoleImageRepository.":{$role}_{$artifactSet}";
    }

    public static function artifactSet(): string
    {
        $namespace = getenv(self::EnvironmentVariable);

        if (is_string($namespace) && trim($namespace) !== '') {
            return self::sanitize($namespace);
        }

        return self::BaseArtifactSet;
    }

    public static function hasCustomArtifactSet(): bool
    {
        $namespace = getenv(self::EnvironmentVariable);

        return is_string($namespace) && trim($namespace) !== '';
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
        if (! str_starts_with($name, 'orbit-template-')) {
            return $name;
        }

        return self::incusTemplateNameForArtifactSet($name, self::artifactSet());
    }

    public static function incusBaseTemplateName(string $name): string
    {
        return self::incusTemplateNameForArtifactSet($name, self::BaseArtifactSet);
    }

    private static function incusTemplateNameForArtifactSet(string $name, string $artifactSet): string
    {
        if (! str_starts_with($name, 'orbit-template-')) {
            return $name;
        }

        return 'orbit-template-'.substr($name, strlen('orbit-template-'))."-{$artifactSet}";
    }

    public static function incusSnapshotName(E2ETopologyKind $kind): string
    {
        return self::incusSnapshotNameForArtifactSet($kind, self::artifactSet());
    }

    public static function incusBaseSnapshotName(E2ETopologyKind $kind): string
    {
        return self::incusSnapshotNameForArtifactSet($kind, self::BaseArtifactSet);
    }

    private static function incusSnapshotNameForArtifactSet(E2ETopologyKind $kind, string $artifactSet): string
    {
        return "clean-{$kind->value}-{$artifactSet}";
    }

    private static function sanitize(string $namespace): string
    {
        $namespace = strtolower(trim($namespace));
        $namespace = preg_replace('/[^a-z0-9.-]+/', '-', $namespace) ?? '';
        $namespace = trim($namespace, '.-');

        if ($namespace === '') {
            throw new \InvalidArgumentException(self::EnvironmentVariable
            .' must contain at least one alphanumeric character.');
        }

        return $namespace;
    }
}
