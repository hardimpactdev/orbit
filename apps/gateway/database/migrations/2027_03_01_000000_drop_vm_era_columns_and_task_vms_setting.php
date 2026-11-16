<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops what nothing reads any more (docs/reference/old-paths.md#task-vm-settings): the key `task_vms` of each Node's stored
 * `settings`, and the columns `nodes.image_refresh_hold`, `tasks.topology`, `tasks.idle_saved_commit` and `tasks.idle_saved_at`.
 * Other settings keys stay, and so does a NULL or unreadable `settings` value. No index, trigger or view names the four columns,
 * so SQLite drops them in place.
 *
 * The rollback adds the four columns back empty, with their old types and defaults. It cannot bring the `task_vms` key back:
 * the backup that the release takes before it migrates holds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update("UPDATE nodes SET settings = json_remove(settings, '$.task_vms')
            WHERE settings IS NOT NULL AND json_valid(settings) AND json_type(settings, '$.task_vms') IS NOT NULL");

        Schema::table('nodes', static function (Blueprint $table): void {
            $table->dropColumn('image_refresh_hold');
        });
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn(['topology', 'idle_saved_commit', 'idle_saved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->boolean('image_refresh_hold')->default(false);
        });
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->text('topology')->nullable();
            $table->string('idle_saved_commit')->nullable();
            $table->timestamp('idle_saved_at')->nullable();
        });
    }
};
