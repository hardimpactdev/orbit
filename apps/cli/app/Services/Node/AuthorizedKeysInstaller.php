<?php

declare(strict_types=1);

namespace App\Services\Node;

use RuntimeException;

final readonly class AuthorizedKeysInstaller
{
    public function install(string $home, string $publicKey): bool
    {
        $home = rtrim($home, '/');
        $sshDirectory = "{$home}/.ssh";
        $authorizedKeys = "{$sshDirectory}/authorized_keys";
        $key = trim($publicKey);

        if ($key === '') {
            return false;
        }

        if (! is_dir($sshDirectory) && ! @mkdir($sshDirectory, 0700, true) && ! is_dir($sshDirectory)) {
            throw new RuntimeException("Could not create SSH directory at {$sshDirectory}.");
        }

        if (! @chmod($sshDirectory, 0700)) {
            throw new RuntimeException("Could not set permissions on SSH directory at {$sshDirectory}.");
        }

        $contents = '';

        if (is_file($authorizedKeys)) {
            $contents = @file_get_contents($authorizedKeys);

            if (! is_string($contents)) {
                throw new RuntimeException("Could not read {$authorizedKeys}.");
            }
        }
        $lines = array_filter(array_map(trim(...), explode("\n", $contents)));

        if (in_array($key, $lines, true)) {
            if (! @chmod($authorizedKeys, 0600)) {
                throw new RuntimeException("Could not set permissions on {$authorizedKeys}.");
            }

            return false;
        }

        $next = rtrim($contents, "\n");
        $next = $next === '' ? $key."\n" : $next."\n".$key."\n";

        if (@file_put_contents($authorizedKeys, $next) === false) {
            throw new RuntimeException("Could not write {$authorizedKeys}.");
        }

        if (! @chmod($authorizedKeys, 0600)) {
            throw new RuntimeException("Could not set permissions on {$authorizedKeys}.");
        }

        return true;
    }
}
