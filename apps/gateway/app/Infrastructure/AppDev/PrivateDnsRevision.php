<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The revision of a private DNS render: `max(stored + 1, now in microseconds)`. It never goes down, also
 * after a database restore, because the clock keeps going. The caller takes it in the transaction that
 * reads the rows, so a revision is a point in time.
 */
final readonly class PrivateDnsRevision
{
    public function next(): string
    {
        return DB::transaction(static function (): string {
            $row = DB::table('private_dns_revision')->where('id', 1)->lockForUpdate()->first();
            $now = (int) (Carbon::now()->getPreciseTimestamp(6));
            $revision = max($now, ((int) ($row->revision ?? 0)) + 1);

            if ($row === null) {
                DB::table('private_dns_revision')->insert(['id' => 1, 'revision' => $revision]);
            } else {
                DB::table('private_dns_revision')->where('id', 1)->update(['revision' => $revision]);
            }

            return (string) $revision;
        });
    }
}
