<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_workspace_leases', function (Blueprint $table): void {
            $table->boolean('recovery_sealed')->default(false);
            $table->boolean('causality_uncertain')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('task_workspace_leases', function (Blueprint $table): void {
            $table->dropColumn(['recovery_sealed', 'causality_uncertain']);
        });
    }
};
