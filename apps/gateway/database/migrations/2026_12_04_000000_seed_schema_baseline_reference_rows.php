<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The schema baseline retains migration history, not its required singleton rows.
        DB::table('project_document_storages')->insertOrIgnore(['id' => 1]);
        DB::table('task_vm_image_recipe')->insertOrIgnore(['id' => 1]);
    }

    public function down(): void
    {
        // Reference rows may now hold configured state; leave them in place.
    }
};
