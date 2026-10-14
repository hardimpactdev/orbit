<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;

final readonly class ComposerPackageCache
{
    /** @return list<array{manager: string, package: string, version: string, url: string, integrity: string|null, key: string}> */
    public function read(string $contents): array
    {
        $lock = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($lock)) {
            throw new TaskVmException('task_vm.package_cache_lock_invalid', 'Composer lock must be an object.');
        }
        $artifacts = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock[$section] ?? [];
            if (! is_array($packages)) {
                throw new TaskVmException('task_vm.package_cache_lock_invalid', 'Composer packages must be a list.');
            }
            foreach ($packages as $package) {
                if (! is_array($package) || ! is_array($package['dist'] ?? null)) {
                    continue;
                }
                $dist = $package['dist'];
                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;
                $url = $dist['url'] ?? null;
                if (! is_string($name) || preg_match('~\A[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*\z~D', $name) !== 1 || ! is_string($version) || ! is_string($url) || ($dist['type'] ?? null) !== 'zip') {
                    continue;
                }
                if (! self::publicArchive($url)) {
                    continue;
                }
                $checksum = $dist['shasum'] ?? '';
                if (! is_string($checksum) || ($checksum !== '' && preg_match('/\A[a-f0-9]{40}\z/D', $checksum) !== 1)) {
                    throw new TaskVmException('task_vm.package_cache_lock_invalid', 'Composer checksum is invalid.');
                }
                $artifacts[] = ['manager' => 'composer', 'package' => $name, 'version' => $version, 'url' => $url, 'integrity' => $checksum === '' ? null : 'sha1-'.$checksum, 'key' => 'composer/'.$name.'/'.sha1($url).'.zip'];
            }
        }

        return $artifacts;
    }

    private static function publicArchive(string $url): bool
    {
        return preg_match('~\Ahttps://api\.github\.com/repos/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/zipball/[a-f0-9]{40}\z~D', $url) === 1
            || preg_match('~\Ahttps://codeload\.github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/(?:legacy\.zip|zip)/[a-f0-9]{40}\z~D', $url) === 1;
    }
}
