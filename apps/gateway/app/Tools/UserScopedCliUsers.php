<?php

declare(strict_types=1);

namespace App\Tools;

use App\Services\Nodes\NodeHostPaths;

final class UserScopedCliUsers
{
    public const string USERNAME_PATTERN = '/^[a-z_][a-z0-9_-]{0,31}$/';

    /**
     * @param  array<array-key, mixed>  $config
     * @return list<string>
     */
    public static function targetUsers(array $config): array
    {
        $defaultUser = self::normalize($config['default_user'] ?? null) ?? 'orbit';
        $users = [$defaultUser];

        foreach (self::additionalUsers($config['install_users'] ?? null) as $user) {
            $users[] = $user;
        }

        return array_values(array_unique($users));
    }

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || preg_match(self::USERNAME_PATTERN, $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    public static function homeDirectory(string $user, ?string $platform = null): string
    {
        return NodeHostPaths::homeDirectoryFor($platform, $user);
    }

    /**
     * @return list<string>
     */
    private static function additionalUsers(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $users = array_filter(
            array_map(self::normalize(...), $value),
            static fn (?string $user): bool => $user !== null,
        );

        return array_values($users);
    }
}
