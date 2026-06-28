<?php

declare(strict_types=1);

namespace App\Tools;

final class ClaudeCodeTool extends BaseTool
{
    public const string USERNAME_PATTERN = '/^[a-z_][a-z0-9_-]{0,31}$/';

    public function slug(): string
    {
        return 'claude-code';
    }

    #[\Override]
    public function category(): string
    {
        return 'runtime';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install'];
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        $users = $this->targetUsers($config);
        $version = $this->installVersion($config);
        $blocks = [];

        foreach ($users as $user) {
            $escapedUser = escapeshellarg($user);
            $escapedVersion = escapeshellarg($version);
            $blocks[] = <<<BASH
                # Install Claude Code for {$user}
                sudo -u {$escapedUser} -H bash -lc 'curl -fsSL https://claude.ai/install.sh | bash -s "$1"' bash {$escapedVersion}
                sudo -u {$escapedUser} -H bash -lc 'claude --version'
                BASH;
        }

        return "#!/usr/bin/env bash\n# orbit install claude-code\nset -e\n\n".implode("\n\n", $blocks);
    }

    #[\Override]
    public function probeMetadata(): array
    {
        $user = escapeshellarg('orbit');
        $binary = '/home/orbit/.local/bin/claude';

        return [
            'binary' => $binary,
            'version_command' => sprintf(
                'sudo -u %s -H bash -lc %s',
                $user,
                escapeshellarg("{$binary} --version"),
            ),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return list<string>
     */
    public function targetUsers(array $config): array
    {
        $defaultUser = $this->normalizeUsername($config['default_user'] ?? null) ?? 'orbit';
        $users = [$defaultUser];

        foreach ($this->additionalUsers($config['install_users'] ?? null) as $user) {
            $users[] = $user;
        }

        return array_values(array_unique($users));
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private function installVersion(array $config): string
    {
        $version = $config['version'] ?? null;

        if (! is_string($version) || trim($version) === '') {
            return 'latest';
        }

        return trim($version);
    }

    /**
     * @return list<string>
     */
    private function additionalUsers(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $users = [];

        foreach ($value as $candidate) {
            $normalized = $this->normalizeUsername($candidate);

            if ($normalized !== null) {
                $users[] = $normalized;
            }
        }

        return $users;
    }

    private function normalizeUsername(mixed $value): ?string
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
}
