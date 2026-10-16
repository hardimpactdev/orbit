<?php

declare(strict_types=1);

use App\Domain\Routes\ReservedPrivateHostname;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * No Cluster or Node TLD may cover private names ({@see ReservedPrivateHostname::isPrivateTld()}). The requests refuse
 * a new one. This refuses to upgrade over a stored one, so the private DNS render never meets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $covering = [];

        foreach (['clusters' => 'Cluster', 'nodes' => 'Node'] as $table => $kind) {
            foreach (DB::table($table)->whereNotNull('tld')->orderBy('id')->get(['name', 'tld']) as $row) {
                $tld = strtolower(trim((string) $row->tld, " \t\n\r\0\x0B."));

                if (ReservedPrivateHostname::isPrivateTld($tld)) {
                    $covering[] = "{$kind} [{$row->name}] has the TLD [{$row->tld}]";
                }
            }
        }

        if ($covering !== []) {
            throw new RuntimeException(implode('; ', $covering).'. A TLD may not cover private names. Change it on the current release, then deploy again.');
        }
    }

    public function down(): void {}
};
