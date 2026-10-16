<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No backfill: a workspace name cannot establish ownership after an association was lost.
        DB::statement('ALTER TABLE instances ADD COLUMN task_workspace_owner_id INTEGER NULL REFERENCES tasks(id) ON DELETE SET NULL');
        Schema::table('instances', fn (Blueprint $table) => $table->index('task_workspace_owner_id'));
    }

    public function down(): void
    {
        Schema::table('instances', fn (Blueprint $table) => $table->dropIndex(['task_workspace_owner_id']));
        DB::statement('ALTER TABLE instances DROP COLUMN task_workspace_owner_id');
    }
};
