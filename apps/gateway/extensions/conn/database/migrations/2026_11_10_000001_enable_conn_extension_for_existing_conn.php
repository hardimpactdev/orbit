<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Conn layer became the `conn` extension. An install that already uses it keeps it on; every other install starts
 * with the switch off, like the other extensions (docs/reference/extensions.md#defaults).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('conn_environments')->exists() && ! DB::table('conn_profiles')->exists()) {
            return;
        }

        $now = now();
        DB::table('settings')->updateOrInsert(
            ['scope_type' => 'gateway', 'scope_id' => 0, 'key' => 'extension.conn.enabled'],
            ['value' => '1', 'is_secret' => false, 'created_at' => $now, 'updated_at' => $now],
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('scope_type', 'gateway')->where('scope_id', 0)
            ->where('key', 'extension.conn.enabled')->delete();
    }
};
