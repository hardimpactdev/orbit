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
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->unsignedBigInteger('operation_id')->nullable();
        });
        DB::statement("UPDATE instance_runtimes SET operation_id = (SELECT id FROM operations WHERE kind = 'instance.migrate' AND target_type = 'instance' AND target_id = instance_runtimes.instance_id ORDER BY id DESC LIMIT 1)");
    }

    public function down(): void
    {
        Schema::table('instance_runtimes', function (Blueprint $table): void {
            $table->dropColumn('operation_id');
        });
    }
};
