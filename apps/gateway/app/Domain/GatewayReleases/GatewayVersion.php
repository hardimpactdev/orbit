<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use Illuminate\Support\Facades\Config;

/**
 * The version the Gateway reports in its status: `APP_VERSION` when it is set, else the commit in
 * the release's `REVISION` file, else `dev`.
 */
final class GatewayVersion
{
    /** The version of the running Gateway; its release's `REVISION` file is at the repository root. */
    public static function current(): string
    {
        return self::resolve(Config::get('app.version'), dirname(base_path(), 2).'/REVISION');
    }

    public static function resolve(mixed $configured, string $revisionFile): string
    {
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        if (is_file($revisionFile)) {
            $revision = trim((string) @file_get_contents($revisionFile));

            if (GatewayReleaseCommit::isSha($revision)) {
                return $revision;
            }
        }

        return 'dev';
    }
}
