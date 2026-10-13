<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Plants every command-capable network key that code running as the managed user could write into a checkout's
 * `.git/config`. Each one creates a marker file when Git runs it. A credentialed Gateway call that reads the checkout
 * config would leave one.
 */
final class PlantedGitConfig
{
    /** @param list<string> $urls URLs to rewrite to an `ext::` command */
    public static function plant(string $checkout, string $markers, array $urls): void
    {
        if (! is_dir($markers)) {
            mkdir($markers, 0755, true);
        }
        $marker = static fn (string $name): string => escapeshellarg($markers.'/'.$name);
        $included = $markers.'/included.gitconfig';
        file_put_contents($included, "[credential]\n\thelper = \"!touch {$markers}/include-credential\"\n");
        $config = [
            ['credential.helper', '!touch '.$marker('credential')],
            ['core.sshCommand', 'touch '.$marker('ssh-command').';'],
            ['core.fsmonitor', 'touch '.$marker('fsmonitor').';'],
            ['remote.origin.uploadpack', 'touch '.$marker('uploadpack').'; git-upload-pack'],
            ['remote.origin.receivepack', 'touch '.$marker('receivepack').'; git-receive-pack'],
            ['protocol.ext.allow', 'always'],
            ['include.path', $included],
        ];
        foreach ($config as [$key, $value]) {
            (new Process(['git', '-C', $checkout, 'config', '--add', $key, $value]))->mustRun();
        }
        foreach ($urls as $url) {
            (new Process(['git', '-C', $checkout, 'config', '--add', 'url.ext::sh -c touch% '.$markers.'/ext.insteadOf', $url]))->mustRun();
        }
    }

    /** @return list<string> */
    public static function markers(string $markers): array
    {
        return array_values(array_diff(scandir($markers) ?: [], ['.', '..', 'included.gitconfig']));
    }
}
