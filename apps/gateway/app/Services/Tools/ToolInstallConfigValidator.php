<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Tools\UserScopedCliTool;
use App\Tools\UserScopedCliUsers;

final class ToolInstallConfigValidator
{
    /**
     * @param  array<array-key, mixed>  $config
     */
    public function validate(string $tool, array $config): ?ToolRegistryFailure
    {
        if (! array_key_exists('install_users', $config)) {
            return null;
        }

        if (! app(ToolCatalog::class)->definition($tool) instanceof UserScopedCliTool) {
            return ToolRegistryFailure::validation(
                field: 'config.install_users',
                value: '',
                message: 'Install users are only supported for user-scoped CLI tools.',
                meta: ['reason' => 'unsupported_field'],
            );
        }

        if (! is_array($config['install_users'])) {
            return ToolRegistryFailure::validation(
                field: 'config.install_users',
                value: get_debug_type($config['install_users']),
                message: 'Install users must be a list of Linux usernames.',
                meta: ['reason' => 'unsupported_value'],
            );
        }

        $invalidValues = array_filter(
            array_map(
                static function (mixed $username): ?string {
                    if (
                        is_string($username)
                        && $username !== ''
                        && preg_match(UserScopedCliUsers::USERNAME_PATTERN, $username) === 1
                    ) {
                        return null;
                    }

                    return is_scalar($username) ? (string) $username : get_debug_type($username);
                },
                $config['install_users'],
            ),
            static fn (?string $value): bool => $value !== null,
        );

        if ($invalidValues !== []) {
            $value = array_first($invalidValues);

            return ToolRegistryFailure::validation(
                field: 'config.install_users',
                value: $value,
                message: "Invalid install user '{$value}'.",
                meta: ['reason' => 'unsupported_value'],
            );
        }

        return null;
    }
}
