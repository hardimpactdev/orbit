<?php

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
        Schema::table('template_favorites', function (Blueprint $table) {
            $table->string('db_driver')->nullable()->after('last_used_at');
            $table->string('session_driver')->nullable()->after('db_driver');
            $table->string('cache_driver')->nullable()->after('session_driver');
            $table->string('queue_driver')->nullable()->after('cache_driver');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('template_favorites', function (Blueprint $table) {
            $table->dropColumn(['db_driver', 'session_driver', 'cache_driver', 'queue_driver']);
        });
    }
};
