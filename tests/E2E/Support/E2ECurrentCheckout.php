<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class E2ECurrentCheckout
{
    public static function install(E2EInstance $instance, string $user, SshKeyPair $keyPair): string
    {
        $remotePath = "/home/{$user}/orbit-current";
        $tarball = self::buildArchive();

        try {
            self::copyArchive($tarball, $instance);

            E2ECommand::ssh(
                $instance,
                $user,
                $keyPair,
                "rm -rf {$remotePath} && mkdir -p {$remotePath} && tar --warning=no-unknown-keyword -xzf /tmp/orbit-current.tar.gz -C {$remotePath} && sudo rm -f /tmp/orbit-current.tar.gz && cd {$remotePath} && composer install --no-interaction --prefer-dist --optimize-autoloader && cp .env.example .env && php artisan key:generate --ansi && touch database/database.sqlite && php artisan migrate --force --ansi",
                timeoutSeconds: 600,
            );

            return $remotePath;
        } finally {
            if (is_file($tarball)) {
                @unlink($tarball);
            }
        }
    }

    private static function buildArchive(): string
    {
        $tarball = sys_get_temp_dir().'/orbit-current-'.bin2hex(random_bytes(6)).'.tar.gz';

        $excludes = [
            './.git',
            './.env',
            './database/*.sqlite',
            './database/*.sqlite-*',
            './node_modules',
            './storage/framework/cache/data/*',
            './storage/framework/sessions/*',
            './storage/framework/testing/*',
            './storage/framework/views/*',
            './storage/logs/*',
            './vendor',
        ];

        $excludeArgs = implode(' ', array_map(
            fn (string $pattern): string => '--exclude='.escapeshellarg($pattern),
            $excludes,
        ));

        $result = Process::timeout(300)->run(sprintf(
            'COPYFILE_DISABLE=1 tar %s -czf %s -C %s .',
            $excludeArgs,
            escapeshellarg($tarball),
            escapeshellarg(base_path()),
        ));

        if (! $result->successful()) {
            throw new RuntimeException("Failed to build current checkout archive: {$result->errorOutput()}");
        }

        return $tarball;
    }

    private static function copyArchive(string $tarball, E2EInstance $instance): void
    {
        if ($instance instanceof IncusInstance) {
            $instance->copyLocalFileToInstance($tarball, '/tmp/orbit-current.tar.gz');

            return;
        }

        $instance->copyFileToInstance($tarball, '/tmp/orbit-current.tar.gz');
    }
}
