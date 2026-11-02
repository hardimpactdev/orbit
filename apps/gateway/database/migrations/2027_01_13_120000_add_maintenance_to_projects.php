<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dependency maintenance is opt-in per Project (Nick, 2026-11-02): every Project starts off.
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('maintenance')->default(false)->after('merge_check');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('maintenance');
        });
    }
};
