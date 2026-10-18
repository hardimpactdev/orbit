<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use InvalidArgumentException;

/** The recipe owns the complete Vite+ runtime, separately from the mutable global Tool store. */
final readonly class VitePlusRuntimeLock
{
    public static function read(ToolPin $pin): string
    {
        $contents = file_get_contents(resource_path('machine-software/vite-plus-package-lock.json'));
        if ($contents === false || hash('sha256', $contents) !== $pin->lockSha256) {
            throw new InvalidArgumentException('tools.vp.lock_sha256: the Vite+ runtime lock is missing or has a different digest.');
        }
        $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $packages = data_get($lock, 'packages');
        $root = is_array($packages) ? ($packages[''] ?? null) : null;
        if (data_get($lock, 'lockfileVersion') !== 3 || data_get($root, 'dependencies.vite-plus') !== $pin->version) {
            throw new InvalidArgumentException('tools.vp: the runtime lock does not pin this Vite+ version.');
        }
        foreach ($pin->artifacts as $artifact) {
            $digest = hex2bin($artifact->digest);
            if ($digest === false || data_get($packages, 'node_modules/vite-plus.integrity') !== $artifact->algorithm.'-'.base64_encode($digest)) {
                throw new InvalidArgumentException('tools.vp: the runtime lock and recipe artifact have different integrity pins.');
            }
        }

        return $contents;
    }
}
