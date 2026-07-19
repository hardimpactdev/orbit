<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Illuminate\Support\Facades\File;
use RuntimeException;

class DnsmasqProjectionRollback
{
    /**
     * @param  list<string>  $published
     * @param  array<string, string|null>  $originals
     */
    public function restore(array $published, array $originals): void
    {
        foreach (array_reverse($published) as $target) {
            $original = $originals[$target] ?? null;

            if ($original === null) {
                if (! File::delete($target) && File::exists($target)) {
                    throw new RuntimeException("Could not roll back DNS projection [{$target}].");
                }

                continue;
            }

            $temporary = tempnam(directory: dirname($target), prefix: '.orbit-dns-rollback-');

            if ($temporary === false || file_put_contents($temporary, $original, LOCK_EX) === false) {
                throw new RuntimeException("Could not stage DNS projection rollback [{$target}].");
            }

            if (! rename($temporary, $target)) {
                File::delete($temporary);

                throw new RuntimeException("Could not roll back DNS projection [{$target}].");
            }
        }
    }
}
