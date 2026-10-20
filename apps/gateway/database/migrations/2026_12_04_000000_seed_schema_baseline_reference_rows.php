<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Schema dumps retain migration history, not the singleton rows created by that history.
        DB::table('project_document_storages')->insertOrIgnore(['id' => 1]);
        DB::table('task_vm_image_recipe')->insertOrIgnore(['id' => 1]);
    }

    public function down(): void
    {
        throw new RuntimeException('The schema baseline reference rows cannot be rolled back.');
    }
};
