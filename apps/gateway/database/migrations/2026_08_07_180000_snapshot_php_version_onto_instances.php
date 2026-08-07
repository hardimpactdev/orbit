<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * App PHP version becomes a creation-time template rather than a live parent.
 *
 * Every instance gains its own concrete `php_version`, backfilled from the app
 * value it resolves to today, and every workspace that stored `null` (meaning
 * "follow the app") is materialized to the same effective value. After this
 * migration no runtime changes version, and changing an app default no longer
 * reaches into anything that already exists.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('instances', static function (Blueprint $table): void {
            $table->string('php_version')->nullable()->after('name');
        });

        // Snapshot: each instance keeps exactly the version it resolves to now.
        DB::table('instances')
            ->whereNull('php_version')
            ->update([
                'php_version' => DB::raw('(select php_version from apps where apps.id = instances.app_id)'),
            ]);

        // Workspaces storing null were following the app; materialize that same
        // value so their runtime is unchanged and no longer moves implicitly.
        DB::table('workspaces')
            ->whereNull('php_version')
            ->update([
                'php_version' => DB::raw('(select php_version from apps where apps.id = workspaces.app_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('instances', static function (Blueprint $table): void {
            $table->dropColumn('php_version');
        });
    }
};
