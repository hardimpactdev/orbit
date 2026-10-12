<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Network Git reaches the Project repository URL, never the checkout's configured origin. Tests point that URL at a
 * local bare repository through a global `insteadOf`, the managed user's own config, which a checkout cannot change.
 */
final class GitRemoteRedirect
{
    public static function append(string $config, string $repository, string $url): void
    {
        file_put_contents($config, "[url \"{$repository}\"]\n\tinsteadOf = {$url}\n", FILE_APPEND);
    }

    /** Uses a fresh global config for this process until clear(). */
    public static function global(string $repository, string $url): void
    {
        $config = dirname($repository).'/redirect.gitconfig';
        self::append($config, $repository, $url);
        // Symfony Process passes on only variables that $_SERVER also holds.
        putenv('GIT_CONFIG_GLOBAL='.$config);
        $_SERVER['GIT_CONFIG_GLOBAL'] = $_ENV['GIT_CONFIG_GLOBAL'] = $config;
    }

    public static function clear(): void
    {
        putenv('GIT_CONFIG_GLOBAL');
        unset($_SERVER['GIT_CONFIG_GLOBAL'], $_ENV['GIT_CONFIG_GLOBAL']);
    }
}
