<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Tools\ClaudeCodeTool;

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

        if ($tool !== 'claude-code') {
            return ToolRegistryFailure::validation(
                field: 'config.install_users',
                value: '',
                message: 'Install users are only supported for claude-code.',
                meta: ['reason' => 'unsupported_field'],
            );
        }

        $installUsers = $config['install_users'];

        if (! is_array($installUsers)) {
            return ToolRegistryFailure::validation(
                field: 'config.install_users',
                value: get_debug_type($installUsers),
                message: 'Install users must be a list of Linux usernames.',
                meta: ['reason' => 'unsupported_value'],
            );
        }

        foreach ($installUsers as $username) {
            if (
                is_string($username)
                && $username !== ''
                && preg_match(ClaudeCodeTool::USERNAME_PATTERN, $username) === 1
            ) {
                continue;
            }

            $value = is_scalar($username) ? (string) $username : get_debug_type($username);

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
