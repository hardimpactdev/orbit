<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use InvalidArgumentException;

final readonly class LocalWorkspaceSetupStepEnvironment
{
    private const string ENVIRONMENT_KEY_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * @return array<string, string>
     */
    public static function from(mixed $value): array
    {
        if (! self::isStringMap($value)) {
            throw new InvalidArgumentException('Workspace setup environment is invalid.');
        }

        /** @var array<string, string> $environment */
        $environment = $value;

        foreach (array_keys($environment) as $key) {
            if (preg_match(self::ENVIRONMENT_KEY_PATTERN, $key) !== 1 || self::isForbiddenKey($key)) {
                throw new InvalidArgumentException('Workspace setup environment is invalid.');
            }
        }

        return $environment;
    }

    private static function isForbiddenKey(string $key): bool
    {
        if ($key === 'APP_KEY') {
            return true;
        }

        return (
            str_starts_with($key, 'ORBIT_AGENT_PUSH_AUTHORIZED_') || str_starts_with($key, 'ORBIT_TRUSTED_EXECUTION_')
        );
    }

    private static function isStringMap(mixed $value): bool
    {
        if (! is_array($value) || ! array_all(array_keys($value), static fn ($key) => is_string($key))) {
            return false;
        }

        return array_all($value, static fn ($entry) => is_string($entry) && ! str_contains($entry, "\0"));
    }
}
