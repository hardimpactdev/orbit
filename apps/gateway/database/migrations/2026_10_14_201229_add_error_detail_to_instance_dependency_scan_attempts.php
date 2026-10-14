<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('instance_dependency_scan_attempts', function (Blueprint $table) {
            $table->string('error_detail', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instance_dependency_scan_attempts', function (Blueprint $table) {
            $table->dropColumn('error_detail');
        });
    }
};
