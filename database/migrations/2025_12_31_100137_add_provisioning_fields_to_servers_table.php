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
        Schema::table('servers', function (Blueprint $table) {
            $table->string('status')->default('active'); // provisioning, active, error
            $table->json('provisioning_log')->nullable();
            $table->string('provisioning_error')->nullable();
            $table->unsignedTinyInteger('provisioning_step')->nullable();
            $table->unsignedTinyInteger('provisioning_total_steps')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['status', 'provisioning_log', 'provisioning_error', 'provisioning_step', 'provisioning_total_steps']);
        });
    }
};
