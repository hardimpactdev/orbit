<?php

declare(strict_types=1);

namespace App\E2E\Support;

final class DockerSocketGroupAdd
{
    /** @var array<string, int> */
    private static array $resolvedGroupIds = [];

    public static function optionFor(DockerHost $host): string
    {
        return '--group-add '.$host->dockerSocketGroupId();
    }

    public static function resolvedGroupId(string $host): ?int
    {
        return self::$resolvedGroupIds[$host] ?? null;
    }

    public static function rememberGroupId(string $host, int $groupId): int
    {
        return self::$resolvedGroupIds[$host] = $groupId;
    }

    public static function resetResolvedGroupIdsForTesting(): void
    {
        self::$resolvedGroupIds = [];
    }
}
