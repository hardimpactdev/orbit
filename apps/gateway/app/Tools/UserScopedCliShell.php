<?php

declare(strict_types=1);

namespace App\Tools;

final class UserScopedCliShell
{
    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function installScript(UserScopedCliTool $tool, array $config): string
    {
        $version = self::installVersion($tool, $config);
        $profile = $tool->cliProfile();
        $blocks = [];

        foreach (UserScopedCliUsers::targetUsers($config) as $user) {
            $binary = $profile->binaryPath($user);
            $installCommand = $profile->installCommand($version);

            $blocks[] = self::runAsUser($user, $installCommand['command'], ...$installCommand['arguments']);
            $blocks[] = self::runAsUser($user, $profile->versionCommand($binary));
        }

        return "#!/usr/bin/env bash\n# orbit install {$tool->slug()}\nset -e\n\n".implode("\n\n", $blocks);
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function updateScript(UserScopedCliTool $tool, array $config): string
    {
        $profile = $tool->cliProfile();
        $blocks = [];

        foreach (UserScopedCliUsers::targetUsers($config) as $user) {
            $binary = $profile->binaryPath($user);

            $blocks[] = self::runAsUser($user, $profile->updateCommand($user));
            $blocks[] = self::runAsUser($user, $profile->versionCommand($binary));
        }

        return "#!/usr/bin/env bash\n# orbit update {$tool->slug()}\nset -e\n\n".implode("\n\n", $blocks);
    }

    /**
     * @return array{binary: string, version_command: string}
     */
    public static function probeMetadataForUser(UserScopedCliTool $tool, string $user): array
    {
        $profile = $tool->cliProfile();
        $binary = $profile->binaryPath($user);

        return [
            'binary' => $binary,
            'version_command' => self::runAsUser($user, $profile->versionCommand($binary)),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function installVersion(UserScopedCliTool $tool, array $config): string
    {
        if (! $tool->supportsInstallVersion()) {
            return 'latest';
        }

        if (! array_key_exists('version', $config) || ! is_string($config['version'])) {
            return 'latest';
        }

        $version = trim($config['version']);

        return $version === '' ? 'latest' : $version;
    }

    public static function runAsUser(string $user, string $command, string ...$arguments): string
    {
        return sprintf(
            'sudo -u %s -H bash -lc %s%s',
            escapeshellarg($user),
            escapeshellarg($command),
            self::shellArguments($arguments),
        );
    }

    private static function shellArguments(array $arguments): string
    {
        if ($arguments === []) {
            return '';
        }

        return ' '.implode(' ', array_map(escapeshellarg(...), $arguments));
    }
}
